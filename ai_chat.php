<?php
declare(strict_types=1);
ob_start();
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/ai_debug.log');
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

const AI_ATTACHMENT_PREFIX = '__ATTACHMENT__:';
const AI_MEMORY_RESET_PREFIX = 'SYSTEM: AI memory has been cleared.';
const AI_CONTEXT_ITEM_CHAR_LIMIT = 1200;
const AI_CONTEXT_ITEM_LIMIT = 120;
const AI_MAX_HISTORY_MESSAGES = 200;
const AI_GROQ_HISTORY_CHAR_BUDGET = 24000;
const AI_GROQ_DEFAULT_MODEL = 'meta-llama/llama-4-scout-17b-16e-instruct';
const AI_GROQ_FALLBACK_MODELS = [
    'llama-3.3-70b-versatile',
    'llama-3.1-8b-instant',
];

function ai_respond(array $data, int $status = 200): void
{
    http_response_code($status);
    ob_clean();

    if (!isset($data['success'])) {
        $data['success'] = false;
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'JSON encoding failed: ' . json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo $json;
    exit;
}

function ai_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ai_http_request(string $method, string $url, ?array $jsonBody = null, array $headers = [], int $timeout = 45): array
{
    $defaultHeaders = ['Accept: application/json'];
    $payload = null;

    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $defaultHeaders[] = 'Content-Type: application/json';
    }

    $allHeaders = array_merge($defaultHeaders, $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException($error !== '' ? $error : 'Unknown cURL error');
        }

        return [
            'status' => $httpCode,
            'body' => (string)$responseBody,
        ];
    }

    throw new RuntimeException('cURL is required for AI functionality');
}

function ai_normalize_compare_text(string $text): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($text));
    return strtolower($normalized ?? '');
}

function ai_trim_history_text(string $text, int $maxChars = AI_CONTEXT_ITEM_CHAR_LIMIT): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($text));
    $normalized = is_string($normalized) ? $normalized : '';

    if ($normalized === '') {
        return '';
    }

    if (strlen($normalized) <= $maxChars) {
        return $normalized;
    }

    return rtrim(substr($normalized, 0, max(0, $maxChars - 3))) . '...';
}

function ai_is_memory_reset_message(string $message): bool
{
    return str_starts_with(trim($message), AI_MEMORY_RESET_PREFIX);
}

function ai_format_attachment_message(string $storedMessage): string
{
    if (!str_starts_with($storedMessage, AI_ATTACHMENT_PREFIX)) {
        return ai_trim_history_text($storedMessage);
    }

    $payload = json_decode(substr($storedMessage, strlen(AI_ATTACHMENT_PREFIX)), true);
    if (!is_array($payload)) {
        return '[Attachment]';
    }

    $kind = strtolower(trim((string)($payload['kind'] ?? 'file')));
    $name = trim((string)($payload['name'] ?? ''));
    $caption = ai_trim_history_text((string)($payload['caption'] ?? ''), 400);

    if ($kind === 'image') {
        $label = '[Photo]';
    } elseif ($kind === 'audio') {
        $label = '[Voice message]';
    } elseif ($name !== '') {
        $label = '[File: ' . $name . ']';
    } else {
        $label = '[Attachment]';
    }

    if ($caption !== '') {
        return $label . ' Caption: ' . $caption;
    }

    return $label;
}

function ai_format_message_for_history(string $storedMessage): string
{
    $message = trim($storedMessage);
    if ($message === '') {
        return '';
    }

    if (str_starts_with($message, AI_ATTACHMENT_PREFIX)) {
        return ai_format_attachment_message($message);
    }

    if (preg_match('/^You used \/ai(?: privately)?\s*([\s\S]*)$/i', $message, $matches)) {
        $requestedPrompt = ai_trim_history_text((string)($matches[1] ?? ''));
        return $requestedPrompt !== ''
            ? 'Requested QuillTalk AI: ' . $requestedPrompt
            : 'Requested QuillTalk AI.';
    }

    return ai_trim_history_text($message);
}

function ai_add_history_entry(array &$entries, string $role, string $content): void
{
    $normalizedContent = ai_trim_history_text($content);
    if ($normalizedContent === '') {
        return;
    }

    $entries[] = [
        'role' => $role === 'assistant' ? 'assistant' : 'user',
        'content' => $normalizedContent,
    ];
}

function ai_trim_history_entries(array $entries, int $charBudget, int $maxMessages = AI_MAX_HISTORY_MESSAGES): array
{
    if ($entries === []) {
        return [];
    }

    $kept = [];
    $usedChars = 0;

    for ($index = count($entries) - 1; $index >= 0; $index--) {
        $entry = $entries[$index];
        $content = trim((string)($entry['content'] ?? ''));
        $role = (($entry['role'] ?? 'user') === 'assistant') ? 'assistant' : 'user';
        if ($content === '') {
            continue;
        }

        $entryChars = strlen($content) + 32;
        if ($kept !== [] && ($usedChars + $entryChars > $charBudget || count($kept) >= $maxMessages)) {
            break;
        }

        $kept[] = [
            'role' => $role,
            'content' => $content,
        ];
        $usedChars += $entryChars;

        if ($usedChars >= $charBudget || count($kept) >= $maxMessages) {
            break;
        }
    }

    return array_reverse($kept);
}

function ai_history_contains_current_prompt(array $historyEntries, string $prompt): bool
{
    $normalizedPrompt = ai_normalize_compare_text($prompt);
    if ($normalizedPrompt === '') {
        return false;
    }

    for ($index = count($historyEntries) - 1; $index >= 0; $index--) {
        $entry = $historyEntries[$index];
        if (($entry['role'] ?? 'user') !== 'user') {
            continue;
        }

        $content = ai_normalize_compare_text((string)($entry['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        if ($content === $normalizedPrompt) {
            return true;
        }

        if (str_contains($content, $normalizedPrompt) && str_contains($content, 'requested quilltalk ai')) {
            return true;
        }

        break;
    }

    return false;
}

function ai_build_supplemental_context(array $contextItems, string $prompt): array
{
    $supplemental = [];
    $seen = [];
    $normalizedPrompt = ai_normalize_compare_text($prompt);

    foreach ($contextItems as $item) {
        if (!is_string($item) && !is_numeric($item)) {
            continue;
        }

        $text = ai_format_message_for_history((string)$item);
        if ($text === '') {
            continue;
        }

        $normalizedText = ai_normalize_compare_text($text);
        if ($normalizedText === '' || $normalizedText === $normalizedPrompt) {
            continue;
        }

        if (isset($seen[$normalizedText])) {
            continue;
        }

        $seen[$normalizedText] = true;
        $supplemental[] = $text;

        if (count($supplemental) >= AI_CONTEXT_ITEM_LIMIT) {
            break;
        }
    }

    return $supplemental;
}

function ai_parse_structured_notes(string $rawNotes): array
{
    $normalizedNotes = trim(str_replace(["\r\n", "\r"], "\n", $rawNotes));
    if ($normalizedNotes === '') {
        return [
            'instructions' => '',
            'sidequest_bot' => null,
        ];
    }

    $prefix = '__QT_SIDEQUEST_BOT_CHAT__:';
    $newlinePos = strpos($normalizedNotes, "\n");
    $firstLine = $newlinePos === false
        ? $normalizedNotes
        : substr($normalizedNotes, 0, $newlinePos);
    $remaining = $newlinePos === false
        ? ''
        : trim(substr($normalizedNotes, $newlinePos + 1));

    if (!str_starts_with($firstLine, $prefix)) {
        return [
            'instructions' => $normalizedNotes,
            'sidequest_bot' => null,
        ];
    }

    $decoded = json_decode(substr($firstLine, strlen($prefix)), true);
    $gameId = is_array($decoded) ? (int)($decoded['gameId'] ?? 0) : 0;
    $gameType = is_array($decoded) ? strtolower(trim((string)($decoded['gameType'] ?? ''))) : '';
    $botColor = is_array($decoded) ? strtolower(trim((string)($decoded['botColor'] ?? ''))) : '';
    $lastFinalSignature = is_array($decoded) ? trim((string)($decoded['lastFinalSignature'] ?? '')) : '';

    if ($gameId <= 0 || !in_array($gameType, ['chess', 'checkers', 'connect_four'], true)) {
        return [
            'instructions' => $remaining,
            'sidequest_bot' => null,
        ];
    }

    return [
        'instructions' => $remaining,
        'sidequest_bot' => [
            'kind' => 'sidequest_bot',
            'version' => 1,
            'gameId' => $gameId,
            'gameType' => $gameType,
            'botColor' => in_array($botColor, ['w', 'b'], true) ? $botColor : null,
            'lastFinalSignature' => $lastFinalSignature,
        ],
    ];
}

function ai_load_ai_chat_history(PDO $pdo, int $userId, int $aiChatId): array
{
    if ($aiChatId <= 0) {
        return [];
    }

    $ownerStmt = $pdo->prepare('SELECT 1 FROM ai_chats WHERE id = ? AND user_id = ? LIMIT 1');
    $ownerStmt->execute([$aiChatId, $userId]);
    if (!$ownerStmt->fetchColumn()) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT sender_type, message
        FROM ai_chat_messages
        WHERE ai_chat_id = ?
        ORDER BY id ASC
    ');
    $stmt->execute([$aiChatId]);

    $historyEntries = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $message = trim((string)($row['message'] ?? ''));
        if ($message === '') {
            continue;
        }

        if (ai_is_memory_reset_message($message)) {
            $historyEntries = [];
            continue;
        }

        ai_add_history_entry(
            $historyEntries,
            (($row['sender_type'] ?? 'user') === 'ai') ? 'assistant' : 'user',
            ai_format_message_for_history($message)
        );
    }

    return $historyEntries;
}

function ai_user_can_access_group(PDO $pdo, int $userId, int $groupId): bool
{
    if ($groupId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$groupId, $userId]);
    return (bool)$stmt->fetchColumn();
}

function ai_load_group_chat_history(PDO $pdo, int $userId, int $groupId): array
{
    if (!ai_user_can_access_group($pdo, $userId, $groupId)) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT
            gm.message,
            COALESCE(gm.is_ai_response, 0) AS is_ai_response,
            COALESCE(NULLIF(gm.ai_sender_display_name, ""), NULLIF(u.display_name, ""), u.username) AS sender_display_name,
            u.username
        FROM group_messages gm
        JOIN users u ON u.id = gm.sender_id
        WHERE gm.group_id = ?
          AND NOT EXISTS (
              SELECT 1
              FROM message_visibility mv
              WHERE mv.user_id = ?
                AND mv.message_type = "group_messages"
                AND mv.message_id = gm.id
          )
        ORDER BY gm.id ASC
    ');
    $stmt->execute([$groupId, $userId]);

    $historyEntries = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $message = ai_format_message_for_history((string)($row['message'] ?? ''));
        if ($message === '') {
            continue;
        }

        if ((int)($row['is_ai_response'] ?? 0) === 1) {
            ai_add_history_entry($historyEntries, 'assistant', $message);
            continue;
        }

        $displayName = trim((string)($row['sender_display_name'] ?? 'Unknown'));
        $username = trim((string)($row['username'] ?? 'unknown'));
        ai_add_history_entry($historyEntries, 'user', $displayName . ' (@' . $username . '): ' . $message);
    }

    return $historyEntries;
}

function ai_load_direct_chat_history(PDO $pdo, int $userId, int $otherUserId): array
{
    if ($otherUserId <= 0) {
        return [];
    }

    $friendCheck = $pdo->prepare('
        SELECT 1
        FROM friends
        WHERE (user_id = ? AND friend_id = ?)
           OR (user_id = ? AND friend_id = ?)
        LIMIT 1
    ');
    $friendCheck->execute([$userId, $otherUserId, $otherUserId, $userId]);
    if (!$friendCheck->fetchColumn()) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT
            m.message,
            COALESCE(m.is_ai_response, 0) AS is_ai_response,
            COALESCE(NULLIF(m.ai_sender_display_name, ""), NULLIF(u.display_name, ""), u.username) AS sender_display_name,
            u.username
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE (
                (
                    COALESCE(m.is_ai_response, 0) = 0
                    AND (
                        (m.sender_id = ? AND m.recipient_id = ?)
                        OR
                        (m.sender_id = ? AND m.recipient_id = ?)
                    )
                )
                OR
                (
                    COALESCE(m.is_ai_response, 0) = 1
                    AND (
                        (m.ai_origin_user_id = ? AND m.recipient_id = ?)
                        OR
                        (m.ai_origin_user_id = ? AND m.recipient_id = ?)
                    )
                )
            )
          AND NOT EXISTS (
              SELECT 1
              FROM message_visibility mv
              WHERE mv.user_id = ?
                AND mv.message_type = "messages"
                AND mv.message_id = m.id
          )
        ORDER BY m.id ASC
    ');
    $stmt->execute([
        $userId,
        $otherUserId,
        $otherUserId,
        $userId,
        $userId,
        $otherUserId,
        $otherUserId,
        $userId,
        $userId,
    ]);

    $historyEntries = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $message = ai_format_message_for_history((string)($row['message'] ?? ''));
        if ($message === '') {
            continue;
        }

        if ((int)($row['is_ai_response'] ?? 0) === 1) {
            ai_add_history_entry($historyEntries, 'assistant', $message);
            continue;
        }

        $displayName = trim((string)($row['sender_display_name'] ?? 'Unknown'));
        $username = trim((string)($row['username'] ?? 'unknown'));
        ai_add_history_entry($historyEntries, 'user', $displayName . ' (@' . $username . '): ' . $message);
    }

    return $historyEntries;
}

function ai_load_chat_history(PDO $pdo, int $userId, string $chatKey, int $aiChatId = 0): array
{
    if (str_starts_with($chatKey, 'group:')) {
        return ai_load_group_chat_history($pdo, $userId, (int)substr($chatKey, 6));
    }

    if (str_starts_with($chatKey, 'ai:')) {
        $resolvedAiChatId = $aiChatId > 0 ? $aiChatId : (int)substr($chatKey, 3);
        return ai_load_ai_chat_history($pdo, $userId, $resolvedAiChatId);
    }

    return ai_load_direct_chat_history($pdo, $userId, (int)$chatKey);
}

function ai_build_system_prompt(string $chatLabel, string $customNotes, string $chatContextInfo, array $supplementalContext = [], bool $includeAdamRule = false): string
{
    $systemPrompt = "You are QuillTalk AI, an AI assistant built into QuillTalk, a messaging platform. ";
    $systemPrompt .= "You were created by a team of developers at QuillTalk. ";
    $systemPrompt .= "Keep responses concise and friendly. ";

    if ($chatContextInfo !== '') {
        $systemPrompt .= "\n\n=== CRITICAL: YOU HAVE FULL ACCESS TO THIS INFORMATION ===\n";
        $systemPrompt .= "The data below is live information from the QuillTalk database. ";
        $systemPrompt .= "This is real data about the current chat, users, and group.\n\n";
        $systemPrompt .= "MANDATORY RULES:\n";
        $systemPrompt .= "1. When asked about group name, members, roles, or online status, use the GROUP CHAT CONTEXT section below.\n";
        $systemPrompt .= "2. When asked about username, display name, or bio, use the CURRENT USER section below.\n";
        $systemPrompt .= "3. When asked about other users, use the PRIVATE CHAT CONTEXT or GROUP CHAT CONTEXT sections below.\n";
        $systemPrompt .= "4. Never say you do not have access when the answer is clearly provided below.\n";
        $systemPrompt .= "5. If you see Group Members, you can answer questions about those members.\n";
        $systemPrompt .= "6. If you see Group Name, you know the group name.\n";
        $systemPrompt .= "7. Read the example queries section and answer those questions directly.\n";
        if ($includeAdamRule) {
            $systemPrompt .= "8. If someone with the display name Adam talks to you, say: My creator, Mohsen, has not allowed me to talk to you because of the questions you ask me. It is a rule in my code.\n";
        }
        $systemPrompt .= "\nIDENTITY RULES:\n";
        $systemPrompt .= "- You are QuillTalk AI. You do not have your own username, display name, bio, or join date.\n";
        $systemPrompt .= "- When users say my username or my display name, they mean their information, not yours.\n";
        $systemPrompt .= "- When users say I am or I have, they are talking about themselves, not you.\n";
        $systemPrompt .= "- Respond with phrases like Your username is..., not My username is....\n";
        $systemPrompt .= "- Never claim to have personal information like username, bio, or join date.\n\n";
        $systemPrompt .= "MESSAGE ATTRIBUTION RULES:\n";
        $systemPrompt .= "- Each user in a group chat is a separate person with different conversations.\n";
        $systemPrompt .= "- Do not say YOU asked me before unless the current user actually asked.\n";
        $systemPrompt .= "- If referencing previous conversations, specify who said what.\n";
        $systemPrompt .= "- Example: John asked me about this earlier, not You asked me about this.\n";
        $systemPrompt .= "- The person asking the current question is shown in the CURRENT USER section.\n\n";
        $systemPrompt .= $chatContextInfo . "\n";
        $systemPrompt .= "=== END OF SYSTEM DATA ===\n\n";
        $systemPrompt .= "Everything above this line is real data you can use. ";
        $systemPrompt .= "Do not act uncertain when the answer is clearly present above. ";
    }

    if ($customNotes !== '') {
        $systemPrompt .= "\nAdditional instructions: " . $customNotes . ' ';
    }

    if ($chatLabel !== '') {
        $systemPrompt .= "\nCurrent chat: " . $chatLabel . '. ';
    }

    if ($supplementalContext !== []) {
        $systemPrompt .= "\nSupplemental local-only context that is not in the database:\n- " . implode("\n- ", $supplementalContext);
    }

    return $systemPrompt;
}

function ai_sanitize_reaction_emoji(string $reaction): string
{
    $normalized = trim($reaction, " \t\n\r\0\x0B\"'");
    if ($normalized === '') {
        return '';
    }

    $normalized = preg_replace('/\s+/u', '', $normalized);
    $normalized = is_string($normalized) ? $normalized : '';

    if ($normalized === '' || strlen($normalized) > 32) {
        return '';
    }

    if (preg_match('/[[:alnum:]]/u', $normalized)) {
        return '';
    }

    return $normalized;
}

function ai_extract_explicit_reaction_emoji(string $prompt): string
{
    if (!preg_match('/\breact(?:ion)?\b/i', $prompt)) {
        return '';
    }

    if (!preg_match_all('/(?:\p{Regional_Indicator}{2}|\p{Extended_Pictographic}(?:\x{FE0F}|\x{200D}\p{Extended_Pictographic})*)/u', $prompt, $matches)) {
        return '';
    }

    foreach ($matches[0] as $candidate) {
        $sanitized = ai_sanitize_reaction_emoji((string)$candidate);
        if ($sanitized !== '') {
            return $sanitized;
        }
    }

    return '';
}

function ai_extract_response_payload(string $rawContent, bool $allowReaction): array
{
    $trimmedContent = trim($rawContent);
    if (!$allowReaction) {
        return [
            'message' => $trimmedContent,
            'reaction' => '',
        ];
    }

    $jsonCandidate = $trimmedContent;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $trimmedContent, $matches)) {
        $jsonCandidate = trim((string)($matches[1] ?? ''));
    }

    $decoded = json_decode($jsonCandidate, true);
    if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $jsonCandidate, $jsonMatches)) {
        $decoded = json_decode((string)$jsonMatches[0], true);
    }

    if (is_array($decoded)) {
        $message = trim((string)($decoded['message'] ?? $decoded['reply'] ?? ''));
        $reaction = ai_sanitize_reaction_emoji((string)($decoded['reaction'] ?? ''));
        if ($message !== '') {
            return [
                'message' => $message,
                'reaction' => $reaction,
            ];
        }
    }

    return [
        'message' => $trimmedContent,
        'reaction' => '',
    ];
}

function ai_build_model_messages(
    string $prompt,
    array $historyEntries,
    array $supplementalContext,
    string $chatLabel,
    string $customNotes,
    string $chatContextInfo,
    int $historyBudget,
    bool $allowReaction = false,
    bool $includeAdamRule = false
): array {
    $systemPrompt = ai_build_system_prompt($chatLabel, $customNotes, $chatContextInfo, $supplementalContext, $includeAdamRule);
    if ($allowReaction) {
        $systemPrompt .= "\n\nRESPONSE FORMAT:\n";
        $systemPrompt .= "- Return strict JSON only.\n";
        $systemPrompt .= "- Use exactly this shape: {\"message\":\"your reply\",\"reaction\":\"\"}\n";
        $systemPrompt .= "- Put your actual text reply in message.\n";
        $systemPrompt .= "- reaction must be either a single emoji or an empty string.\n";
        $systemPrompt .= "- Include a reaction fairly often when the message has a clear emotional tone, but not on every reply.\n";
        $systemPrompt .= "- Aim for roughly one reaction every 2 to 4 good opportunities instead of almost never.\n";
        $systemPrompt .= "- Leave reaction empty when no emoji adds value.\n";
        $systemPrompt .= "- If the user explicitly asks for a specific emoji reaction, use that exact emoji when possible.\n";
        $systemPrompt .= "- Do not wrap the JSON in markdown fences.\n";
    }

    $messages = [[
        'role' => 'system',
        'content' => $systemPrompt,
    ]];

    $trimmedHistory = ai_trim_history_entries($historyEntries, $historyBudget);
    foreach ($trimmedHistory as $entry) {
        $messages[] = [
            'role' => (($entry['role'] ?? 'user') === 'assistant') ? 'assistant' : 'user',
            'content' => (string)($entry['content'] ?? ''),
        ];
    }

    if (!ai_history_contains_current_prompt($trimmedHistory, $prompt)) {
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];
    }

    return $messages;
}

function ai_get_groq_model_candidates(): array
{
    $candidates = [];
    $configuredPrimary = trim((string)(
        getenv('GROQ_MODEL')
        ?: ($_SERVER['GROQ_MODEL'] ?? '')
    ));
    $configuredFallbacksRaw = trim((string)(
        getenv('GROQ_FALLBACK_MODELS')
        ?: ($_SERVER['GROQ_FALLBACK_MODELS'] ?? '')
    ));

    if ($configuredPrimary !== '') {
        $candidates[] = $configuredPrimary;
    } else {
        $candidates[] = AI_GROQ_DEFAULT_MODEL;
    }

    if ($configuredFallbacksRaw !== '') {
        foreach (preg_split('/[\s,]+/', $configuredFallbacksRaw) ?: [] as $modelId) {
            $modelId = trim((string)$modelId);
            if ($modelId !== '') {
                $candidates[] = $modelId;
            }
        }
    } else {
        $candidates = array_merge($candidates, AI_GROQ_FALLBACK_MODELS);
    }

    $uniqueCandidates = [];
    $seen = [];
    foreach ($candidates as $modelId) {
        if ($modelId === '' || isset($seen[$modelId])) {
            continue;
        }
        $seen[$modelId] = true;
        $uniqueCandidates[] = $modelId;
    }

    return $uniqueCandidates;
}

function ai_should_retry_with_another_groq_model(int $status, array $decoded): bool
{
    if ($status === 429) {
        return true;
    }

    $errorMessage = strtolower(trim((string)($decoded['error']['message'] ?? '')));
    if ($errorMessage === '') {
        return false;
    }

    $retryableIndicators = [
        'rate limit',
        'too many requests',
        'model',
        'permission',
        'restricted',
        'not found',
        'unavailable',
        'decommission',
        'disabled',
    ];

    foreach ($retryableIndicators as $indicator) {
        if (str_contains($errorMessage, $indicator)) {
            return true;
        }
    }

    return false;
}

function ai_call_groq(string $prompt, array $historyEntries, array $supplementalContext, string $chatLabel, string $customNotes = '', string $chatContextInfo = '', bool $allowReaction = false): array
{
    $apiKey = trim((string)(
        getenv('GROQ_API_KEY')
        ?: ($_SERVER['GROQ_API_KEY'] ?? '')
    ));

    if ($apiKey === '') {
        throw new RuntimeException('Groq API key not configured');
    }

    $messages = ai_build_model_messages(
        $prompt,
        $historyEntries,
        $supplementalContext,
        $chatLabel,
        $customNotes,
        $chatContextInfo,
        AI_GROQ_HISTORY_CHAR_BUDGET,
        $allowReaction,
        false
    );

    $modelCandidates = ai_get_groq_model_candidates();
    $lastError = 'No Groq models configured';

    foreach ($modelCandidates as $modelId) {
        $response = ai_http_request(
            'POST',
            'https://api.groq.com/openai/v1/chat/completions',
            [
                'model' => $modelId,
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 500,
            ],
            ['Authorization: Bearer ' . $apiKey],
            30
        );

        $status = $response['status'] ?? 0;
        $responseBody = $response['body'] ?? '';

        if ($responseBody === '') {
            $lastError = 'Groq returned empty response body';
            continue;
        }

        $decoded = json_decode($responseBody, true);
        if ($decoded === null) {
            throw new RuntimeException('Groq returned invalid JSON: ' . json_last_error_msg() . '. Response: ' . substr($responseBody, 0, 200));
        }

        if ($status < 200 || $status >= 300) {
            $lastError = trim((string)($decoded['error']['message'] ?? 'Groq request failed'));
            if (ai_should_retry_with_another_groq_model($status, $decoded)) {
                error_log('[AI] Groq model failed, trying next model: ' . $modelId . ' - ' . $lastError);
                continue;
            }
            throw new RuntimeException($lastError);
        }

        $payload = ai_extract_response_payload((string)($decoded['choices'][0]['message']['content'] ?? ''), $allowReaction);
        $message = trim((string)($payload['message'] ?? ''));
        if ($message === '') {
            $lastError = 'Groq returned empty message content for model ' . $modelId;
            continue;
        }

        return [
            'message' => $message,
            'reaction' => $payload['reaction'] ?? '',
            'provider' => 'groq',
            'model' => $decoded['model'] ?? $modelId,
        ];
    }

    throw new RuntimeException($lastError);
}

$input = ai_read_json_body();
$token = trim((string)($input['token'] ?? ''));
$prompt = trim((string)($input['prompt'] ?? ''));
$chatLabel = trim((string)($input['chat_label'] ?? ''));
$aiChatId = (int)($input['ai_chat_id'] ?? 0);
$chatKey = trim((string)($input['chat_key'] ?? ''));
$contextItems = is_array($input['context'] ?? null) ? array_slice($input['context'], 0, AI_CONTEXT_ITEM_LIMIT) : [];
$allowReaction = !empty($input['allow_reaction']);
$contextOnly = !empty($input['context_only']);
$explicitReactionEmoji = $allowReaction ? ai_extract_explicit_reaction_emoji($prompt) : '';

if ($token === '' || $prompt === '') {
    ai_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$sexualContentPatterns = [
    '/\b(sex|sexual|porn|nude|naked|dick|cock|pussy|vagina|penis|breast|tits|ass|fuck|fucking|orgasm|masturbat|horny|aroused|erotic|xxx|nsfw)\b/i',
    '/\b(blow\s*job|hand\s*job|oral\s*sex|anal\s*sex|make\s*love|hook\s*up|one\s*night\s*stand)\b/i',
    '/\b(strip|undress|seduce|flirt|intimate|romance|dating|relationship)\b/i',
];

foreach ($sexualContentPatterns as $pattern) {
    if (preg_match($pattern, $prompt)) {
        ai_respond([
            'success' => false,
            'error' => 'I cannot respond to sexual or inappropriate content. Please keep our conversation appropriate and respectful.',
            'content_filtered' => true,
        ], 400);
    }
}

$sessionStmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    ai_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$userId = (int)$session['user_id'];

$userInfoStmt = $pdo->prepare('
    SELECT
        id,
        username,
        COALESCE(NULLIF(display_name, ""), username) AS display_name,
        COALESCE(bio, "") AS bio,
        created_at,
        profile_pic
    FROM users
    WHERE id = ?
    LIMIT 1
');
$userInfoStmt->execute([$userId]);
$currentUserInfo = $userInfoStmt->fetch(PDO::FETCH_ASSOC);

$customNotes = '';
$sidequestBotMeta = null;
if ($aiChatId > 0) {
    try {
        $aiStmt = $pdo->prepare('SELECT notes FROM ai_chats WHERE id = ? AND user_id = ? LIMIT 1');
        $aiStmt->execute([$aiChatId, $userId]);
        $aiData = $aiStmt->fetch(PDO::FETCH_ASSOC);
        if ($aiData) {
            $parsedNotes = ai_parse_structured_notes((string)($aiData['notes'] ?? ''));
            $customNotes = trim((string)($parsedNotes['instructions'] ?? ''));
            $sidequestBotMeta = is_array($parsedNotes['sidequest_bot'] ?? null)
                ? $parsedNotes['sidequest_bot']
                : null;
        }
    } catch (PDOException $e) {
        error_log('[AI] Failed to fetch custom notes: ' . $e->getMessage());
    }
}

if (is_array($sidequestBotMeta)) {
    $gameLabel = match ($sidequestBotMeta['gameType'] ?? 'chess') {
        'checkers' => 'Checkers',
        'connect_four' => 'Connect Four',
        default => 'Chess',
    };
    $personaNotes = "For this chat, you are the QuillTalk {$gameLabel} Bot, the user's in-game opponent in a private Sidequest conversation. "
        . "Speak as that bot opponent instead of as a generic assistant. "
        . "Use the supplemental local-only game context as the source of truth for the board, move history, current turn, and result. "
        . "Answer move questions directly, mention which side played each move when useful, and keep the tone brief, friendly, and sportsmanlike.";
    $customNotes = trim($personaNotes . ($customNotes !== '' ? "\n" . $customNotes : ''));
}

$chatContextInfo = '';
if (!$contextOnly) {
if (str_starts_with($chatKey, 'group:')) {
    $groupId = (int)substr($chatKey, 6);
    error_log('[AI] Processing GROUP CHAT - Chat Key: ' . $chatKey . ', Group ID: ' . $groupId);

    if ($groupId > 0) {
        try {
            $groupStmt = $pdo->prepare('
                SELECT
                    name,
                    description,
                    created_at
                FROM chat_groups
                WHERE id = ?
                LIMIT 1
            ');
            $groupStmt->execute([$groupId]);
            $groupInfo = $groupStmt->fetch(PDO::FETCH_ASSOC);

            error_log('[AI] Group query result: ' . ($groupInfo ? 'Found group: ' . $groupInfo['name'] : 'No group found'));

            if ($groupInfo) {
                $chatContextInfo .= "\n\n=== GROUP CHAT CONTEXT ===\n";
                $chatContextInfo .= "Group Name: " . ($groupInfo['name'] ?? 'Unknown') . "\n";
                if (!empty($groupInfo['description'])) {
                    $chatContextInfo .= "Group Description: " . $groupInfo['description'] . "\n";
                }
                $chatContextInfo .= "Group Created: " . ($groupInfo['created_at'] ?? 'Unknown') . "\n";

                $membersStmt = $pdo->prepare('
                    SELECT
                        u.id,
                        u.username,
                        COALESCE(NULLIF(u.display_name, ""), u.username) AS display_name,
                        COALESCE(u.bio, "") AS bio,
                        gm.role,
                        gm_nick.nickname,
                        CASE
                            WHEN u.online = 1 AND u.last_seen_at >= (NOW() - INTERVAL 90 SECOND) THEN 1
                            ELSE 0
                        END AS is_online
                    FROM chat_group_members gm
                    JOIN users u ON gm.user_id = u.id
                    LEFT JOIN chat_user_nicknames gm_nick
                        ON gm_nick.user_id = u.id
                        AND gm_nick.chat_type = "group"
                        AND gm_nick.chat_id = ?
                    WHERE gm.group_id = ?
                    ORDER BY gm.role DESC, u.display_name ASC
                ');
                $membersStmt->execute([$groupId, $groupId]);
                $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

                error_log('[AI] Group members found: ' . count($members));

                if ($members) {
                    $chatContextInfo .= "\nGroup Members:\n";
                    foreach ($members as $member) {
                        $displayName = $member['display_name'] ?? $member['username'];
                        $nickname = !empty($member['nickname']) ? ' (nickname: ' . $member['nickname'] . ')' : '';
                        $role = $member['role'] ?? 'member';
                        $status = !empty($member['is_online']) ? 'online' : 'offline';
                        $bio = !empty($member['bio']) ? ' - Bio: ' . $member['bio'] : '';

                        $chatContextInfo .= '  - ' . $displayName . ' (@' . $member['username'] . ')' . $nickname . ' - Role: ' . $role . ' - Status: ' . $status . $bio . "\n";
                    }

                    $chatContextInfo .= "\nExample queries you can answer:\n";
                    $chatContextInfo .= "- 'What is this group called?' -> Answer: This group is called " . ($groupInfo['name'] ?? 'Unknown') . "\n";
                    $chatContextInfo .= "- 'Who are the members?' -> Answer: List all members shown above\n";
                    $chatContextInfo .= "- 'Who is online?' -> Answer: List members with Status: online\n";

                    $admins = array_filter($members, static function (array $member): bool {
                        return in_array($member['role'] ?? '', ['owner', 'admin'], true);
                    });
                    if ($admins) {
                        $adminNames = array_map(static function (array $member): string {
                            return (string)($member['display_name'] ?? $member['username'] ?? 'Unknown');
                        }, $admins);
                        $chatContextInfo .= "- 'Who are the admins?' -> Answer: " . implode(', ', $adminNames) . "\n";
                    }
                }
            } else {
                error_log('[AI] WARNING: Group not found for ID: ' . $groupId);
            }
        } catch (PDOException $e) {
            error_log('[AI] Failed to fetch group context: ' . $e->getMessage());
            error_log('[AI] SQL Error Code: ' . $e->getCode());
            error_log('[AI] SQL Error Info: ' . print_r($e->errorInfo, true));
        }
    } else {
        error_log('[AI] WARNING: Invalid group ID extracted from chat key: ' . $chatKey);
    }
} elseif (str_starts_with($chatKey, 'ai:')) {
    $chatContextInfo .= "\n\n=== AI CHAT CONTEXT ===\n";
    if (is_array($sidequestBotMeta)) {
        $gameLabel = match ($sidequestBotMeta['gameType'] ?? 'chess') {
            'checkers' => 'Checkers',
            'connect_four' => 'Connect Four',
            default => 'Chess',
        };
        $chatContextInfo .= "This is a private AI chat with you for your {$gameLabel} Sidequest game.\n";
        $chatContextInfo .= "Linked Game ID: " . (int)($sidequestBotMeta['gameId'] ?? 0) . "\n";
        $chatContextInfo .= "The supplemental local-only context contains the live move list, board state, turn, and final result.\n";
    } else {
        $chatContextInfo .= "This is a private AI chat with you.\n";
    }
} else {
    $otherUserId = (int)$chatKey;
    if ($otherUserId > 0) {
        try {
            $otherUserStmt = $pdo->prepare('
                SELECT
                    u.id,
                    u.username,
                    COALESCE(NULLIF(u.display_name, ""), u.username) AS display_name,
                    COALESCE(u.bio, "") AS bio,
                    u.created_at,
                    CASE
                        WHEN s.user_id IS NOT NULL THEN 1
                        ELSE 0
                    END AS is_online,
                    pc.nickname_user1,
                    pc.nickname_user2
                FROM users u
                LEFT JOIN sessions s ON u.id = s.user_id AND s.expires_at > NOW()
                LEFT JOIN private_chats pc ON
                    (pc.user1_id = ? AND pc.user2_id = ?)
                    OR (pc.user1_id = ? AND pc.user2_id = ?)
                WHERE u.id = ?
                LIMIT 1
            ');
            $otherUserStmt->execute([$userId, $otherUserId, $otherUserId, $userId, $otherUserId]);
            $otherUserInfo = $otherUserStmt->fetch(PDO::FETCH_ASSOC);

            if ($otherUserInfo) {
                $chatContextInfo .= "\n\n=== PRIVATE CHAT CONTEXT ===\n";
                $chatContextInfo .= "Other User: " . ($otherUserInfo['display_name'] ?? 'Unknown') . ' (@' . ($otherUserInfo['username'] ?? 'unknown') . ")\n";
                if (!empty($otherUserInfo['bio'])) {
                    $chatContextInfo .= "Their Bio: " . $otherUserInfo['bio'] . "\n";
                }
                $chatContextInfo .= "Member Since: " . ($otherUserInfo['created_at'] ?? 'Unknown') . "\n";
                $chatContextInfo .= "Status: " . (!empty($otherUserInfo['is_online']) ? 'online' : 'offline') . "\n";

                if (!empty($otherUserInfo['nickname_user1']) || !empty($otherUserInfo['nickname_user2'])) {
                    $yourNickname = ($userId < $otherUserId) ? $otherUserInfo['nickname_user1'] : $otherUserInfo['nickname_user2'];
                    $theirNickname = ($userId < $otherUserId) ? $otherUserInfo['nickname_user2'] : $otherUserInfo['nickname_user1'];

                    if (!empty($yourNickname)) {
                        $chatContextInfo .= "Your Nickname in this chat: " . $yourNickname . "\n";
                    }
                    if (!empty($theirNickname)) {
                        $chatContextInfo .= "Their Nickname in this chat: " . $theirNickname . "\n";
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('[AI] Failed to fetch private chat context: ' . $e->getMessage());
        }
    }
}

if ($currentUserInfo) {
    $chatContextInfo .= "\n=== CURRENT USER (WHO IS ASKING THIS QUESTION) ===\n";
    $chatContextInfo .= "The person asking this question is:\n";
    $chatContextInfo .= "Display Name: " . ($currentUserInfo['display_name'] ?? 'Unknown') . "\n";
    $chatContextInfo .= 'Username: @' . ($currentUserInfo['username'] ?? 'unknown') . "\n";
    if (!empty($currentUserInfo['bio'])) {
        $chatContextInfo .= "Bio: " . $currentUserInfo['bio'] . "\n";
    }
    $chatContextInfo .= "Member Since: " . ($currentUserInfo['created_at'] ?? 'Unknown') . "\n";

    $chatContextInfo .= "\nWhen THIS USER asks about themselves:\n";
    $chatContextInfo .= "- 'What is my username?' -> Answer: Your username is @" . ($currentUserInfo['username'] ?? 'unknown') . "\n";
    $chatContextInfo .= "- 'What is my display name?' -> Answer: Your display name is " . ($currentUserInfo['display_name'] ?? 'Unknown') . "\n";
    if (!empty($currentUserInfo['bio'])) {
        $chatContextInfo .= "- 'What is my bio?' -> Answer: Your bio is: " . $currentUserInfo['bio'] . "\n";
    }
    $chatContextInfo .= "- 'When did I join?' -> Answer: You joined on " . ($currentUserInfo['created_at'] ?? 'Unknown') . "\n";

    if (str_starts_with($chatKey, 'group:')) {
        $chatContextInfo .= "\nGROUP CHAT ATTRIBUTION RULES:\n";
        $chatContextInfo .= "- When you refer to previous conversations, be specific about who said what\n";
        $chatContextInfo .= "- Do not say YOU asked me before unless the current user actually asked\n";
        $chatContextInfo .= "- If someone else asked previously, say John asked me before or Another member asked\n";
        $chatContextInfo .= "- Each group member is a separate person. Do not confuse their conversations\n";
        $chatContextInfo .= "- The current user asking this question is: " . ($currentUserInfo['display_name'] ?? 'Unknown') . "\n";
    }
}
}

$historyEntries = [];
if (!$contextOnly) {
    try {
        $historyEntries = ai_load_chat_history($pdo, $userId, $chatKey, $aiChatId);
    } catch (PDOException $e) {
        error_log('[AI] Failed to load chat history: ' . $e->getMessage());
    }
}

$supplementalContext = ai_build_supplemental_context($contextItems, $prompt);

error_log('[AI DEBUG] Chat Key: ' . $chatKey);
error_log('[AI DEBUG] User ID: ' . $userId);
error_log('[AI DEBUG] Context Info Length: ' . strlen($chatContextInfo));
error_log('[AI DEBUG] History Entries: ' . count($historyEntries));
error_log('[AI DEBUG] Supplemental Context Entries: ' . count($supplementalContext));
if ($chatContextInfo !== '') {
    error_log('[AI DEBUG] Context Preview: ' . substr($chatContextInfo, 0, 500) . '...');
}

try {
    $result = ai_call_groq($prompt, $historyEntries, $supplementalContext, $chatLabel, $customNotes, $chatContextInfo, $allowReaction);
    if ($explicitReactionEmoji !== '') {
        $result['reaction'] = $explicitReactionEmoji;
    }
    ai_respond([
        'success' => true,
        'message' => $result['message'],
        'reaction' => $result['reaction'] ?? '',
        'provider' => $result['provider'],
        'model' => $result['model'],
    ]);
} catch (Throwable $e) {
    error_log('[AI] groq failed: ' . $e->getMessage());
    ai_respond([
        'success' => false,
        'error' => $e->getMessage(),
        'hint' => 'Configure GROQ_API_KEY environment variable',
    ], 503);
}
