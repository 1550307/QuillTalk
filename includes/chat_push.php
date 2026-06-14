<?php
declare(strict_types=1);

const QT_CHAT_PUSH_POLL_PREFIX = '__POLL__:';
const QT_CHAT_PUSH_ATTACHMENT_PREFIX = '__ATTACHMENT__:';
const QT_CHAT_PUSH_GAME_PREFIX = '__GAME__:';

function qt_chat_push_autoload_vendor(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $autoloadPath = dirname(__DIR__) . '/quilltalk-backend/vendor/autoload.php';
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
    }

    $loaded = true;
}

function qt_chat_push_resolve_firebase_service_account_path(): ?string
{
    $envPath = trim((string)(getenv('QUILLTALK_FIREBASE_SERVICE_ACCOUNT') ?: ''));
    $candidates = array_filter([
        $envPath,
        dirname(__DIR__) . '/firebase-service-account.json',
    ]);

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function qt_chat_push_load_firebase_project_id(?string $serviceAccountPath): string
{
    if ($serviceAccountPath === null || !is_file($serviceAccountPath)) {
        return '';
    }

    $raw = file_get_contents($serviceAccountPath);
    $json = json_decode((string)$raw, true);
    return trim((string)($json['project_id'] ?? ''));
}

function qt_chat_push_send_android(PDO $pdo, array $deviceTokens, array $payload): void
{
    if (!$deviceTokens) {
        return;
    }

    qt_chat_push_autoload_vendor();
    if (!class_exists('\Google\Client')) {
        return;
    }

    $serviceAccountPath = qt_chat_push_resolve_firebase_service_account_path();
    $projectId = qt_chat_push_load_firebase_project_id($serviceAccountPath);
    if ($serviceAccountPath === null || $projectId === '') {
        error_log('[CHAT PUSH ANDROID] Missing firebase-service-account.json or project_id');
        return;
    }

    $client = new \Google\Client();
    $client->setAuthConfig($serviceAccountPath);
    $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
    $httpClient = $client->authorize();
    $deleteStmt = $pdo->prepare('DELETE FROM android_push_tokens WHERE fcm_token = ?');

    foreach ($deviceTokens as $deviceToken) {
        $fcmMessage = [
            'message' => [
                'token' => (string)$deviceToken,
                'data' => [
                    'type' => 'message',
                    'title' => (string)($payload['title'] ?? 'QuillTalk'),
                    'body' => (string)($payload['body'] ?? 'You have a new message'),
                    'url' => (string)($payload['url'] ?? ''),
                ],
                'android' => [
                    'priority' => 'HIGH',
                    'ttl' => '300s',
                ],
            ],
        ];

        try {
            $httpClient->post(
                "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
                ['json' => $fcmMessage]
            );
        } catch (Throwable $e) {
            $message = $e->getMessage();
            error_log('[CHAT PUSH ANDROID FAIL] ' . $message);
            if (
                stripos($message, 'UNREGISTERED') !== false
                || stripos($message, 'registration-token-not-registered') !== false
            ) {
                $deleteStmt->execute([(string)$deviceToken]);
            }
        }
    }
}

function qt_chat_push_message_details(string $storedMessage): array
{
    if (str_starts_with($storedMessage, QT_CHAT_PUSH_POLL_PREFIX)) {
        return [
            'kind' => 'poll',
            'preview' => 'sent a poll',
        ];
    }

    if (str_starts_with($storedMessage, QT_CHAT_PUSH_GAME_PREFIX)) {
        $payload = json_decode(substr($storedMessage, strlen(QT_CHAT_PUSH_GAME_PREFIX)), true);
        $label = '';
        if (is_array($payload)) {
            $label = trim((string)($payload['label'] ?? $payload['game_type'] ?? ''));
        }
        $label = $label !== '' ? $label : 'game';
        return [
            'kind' => 'game',
            'preview' => 'started ' . $label,
        ];
    }

    if (str_starts_with($storedMessage, QT_CHAT_PUSH_ATTACHMENT_PREFIX)) {
        $payload = json_decode(substr($storedMessage, strlen(QT_CHAT_PUSH_ATTACHMENT_PREFIX)), true);
        if (!is_array($payload)) {
            return [
                'kind' => 'attachment',
                'preview' => 'sent an attachment',
            ];
        }

        $caption = trim((string)($payload['caption'] ?? ''));
        $kind = trim((string)($payload['kind'] ?? 'file'));
        if ($kind === 'audio') {
            return [
                'kind' => 'voice',
                'preview' => $caption !== '' ? $caption : 'sent a voice message',
            ];
        }

        if ($kind === 'video') {
            return [
                'kind' => 'video',
                'preview' => $caption !== '' ? $caption : 'sent a video',
            ];
        }

        return [
            'kind' => 'attachment',
            'preview' => $caption !== '' ? $caption : 'sent an attachment',
        ];
    }

    $preview = trim($storedMessage);
    
    // Check if this is an AI command message that needs sender name replacement
    if (str_contains($preview, 'You used /ai')) {
        return [
            'kind' => 'ai_command',
            'preview' => $preview, // Will be processed later with sender name
            'needs_sender_replacement' => true
        ];
    }
    
    return [
        'kind' => 'text',
        'preview' => $preview !== '' ? $preview : 'sent a message',
    ];
}

function qt_chat_push_build_direct_payload(string $storedMessage, string $senderName, string $iconUrl, bool $mentionOnly = false): array
{
    $details = qt_chat_push_message_details($storedMessage);

    // Handle AI command messages - replace "You used /ai" with sender name
    if (isset($details['needs_sender_replacement']) && $details['needs_sender_replacement']) {
        $details['preview'] = str_replace('You used /ai', $senderName . ' used /ai', $details['preview']);
    }

    if ($mentionOnly) {
        return [
            'title' => 'QuillTalk',
            'body' => trim($senderName . ' mentioned you with @: ' . $details['preview']),
            'icon' => $iconUrl,
        ];
    }

    return [
        'title' => $senderName !== '' ? $senderName : 'QuillTalk',
        'body' => (string)$details['preview'],
        'icon' => $iconUrl,
    ];
}

function qt_chat_push_build_group_payload(string $storedMessage, string $senderName, string $groupName, string $iconUrl, bool $mentionOnly = false): array
{
    $details = qt_chat_push_message_details($storedMessage);

    // Handle AI command messages - replace "You used /ai" with sender name
    if (isset($details['needs_sender_replacement']) && $details['needs_sender_replacement']) {
        $details['preview'] = str_replace('You used /ai', $senderName . ' used /ai', $details['preview']);
    }

    if ($mentionOnly) {
        return [
            'title' => 'QuillTalk',
            'body' => trim($senderName . ' mentioned you with @: ' . $details['preview']),
            'icon' => $iconUrl,
        ];
    }

    $safeSenderName = $senderName !== '' ? $senderName : 'Someone';
    $safeGroupName = $groupName !== '' ? $groupName : 'this group';

    switch ($details['kind']) {
        case 'poll':
            $body = $safeSenderName . ' sent a poll to ' . $safeGroupName;
            break;
        case 'game':
            $body = $safeSenderName . ' started a game in ' . $safeGroupName;
            break;
        case 'voice':
            $body = $safeSenderName . ' sent a voice message to ' . $safeGroupName;
            break;
        case 'attachment':
            $body = $safeSenderName . ' sent an attachment to ' . $safeGroupName;
            break;
        case 'ai_command':
            // For AI commands, show the full message (already has sender name replaced)
            $body = $details['preview'];
            break;
        default:
            $body = $safeSenderName . ' to ' . $safeGroupName . ': ' . $details['preview'];
            break;
    }

    return [
        'title' => 'QuillTalk',
        'body' => $body,
        'icon' => $iconUrl,
    ];
}

function qt_chat_push_send_to_user(PDO $pdo, int $recipientId, array $payload): void
{
    if ($recipientId <= 0) {
        return;
    }

    // TEMPORARY: Disable new features for testing - set to false to enable them
    $disableNewFeatures = false;
    
    if (!$disableNewFeatures) {
        // Check if user should receive notifications based on focus state
        if (!qt_chat_push_should_notify_user($pdo, $recipientId)) {
            return;
        }

        // Check for notification batching
        $batchedPayload = qt_chat_push_check_batching($pdo, $recipientId, $payload);
        if ($batchedPayload !== null) {
            $payload = $batchedPayload;
        }
    }

    qt_chat_push_autoload_vendor();

    $subs = [];
    if (
        class_exists('Minishlink\WebPush\WebPush')
        && class_exists('Minishlink\WebPush\Subscription')
    ) {
        $subsStmt = $pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $subsStmt->execute([$recipientId]);
        $subs = $subsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $androidStmt = $pdo->prepare('SELECT fcm_token FROM android_push_tokens WHERE user_id = ?');
    $androidStmt->execute([$recipientId]);
    $androidTokens = array_values(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['fcm_token'] ?? '')),
        $androidStmt->fetchAll(PDO::FETCH_ASSOC)
    )));

    if (!$subs && !$androidTokens) {
        return;
    }

    $siteUrl = 'https://quilltalk.org/';
    $notifyToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO sessions (user_id, token) VALUES (?, ?)')->execute([$recipientId, $notifyToken]);

    $notificationPayload = [
        'title' => (string)($payload['title'] ?? 'QuillTalk'),
        'body' => (string)($payload['body'] ?? 'You have a new message'),
        'url' => trim((string)($payload['url'] ?? '')) ?: ($siteUrl . 'app.php?token=' . $notifyToken),
        'icon' => (string)($payload['icon'] ?? ($siteUrl . 'images/default-profile.png')),
    ];

    if ($subs) {
        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject'    => 'mailto:support@quilltalk.org',
                'publicKey'  => 'BJZapSQDICQ0e02As3dIXEBGMOWGfEelQAiM8f7vE3FzgMUJu2YKB5KqW2xuolEV_5nimni2kC_Pw970TsigMZ0',
                'privateKey' => 'g0NJ1rMUNrO_QiVgDhmb2BEj3cIErtLZwLTy-UN9gpY',
            ]
        ]);

        foreach ($subs as $subscriptionRow) {
            $webPush->queueNotification(
                \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $subscriptionRow['endpoint'],
                    'keys' => [
                        'p256dh' => $subscriptionRow['p256dh'],
                        'auth' => $subscriptionRow['auth'],
                    ],
                ]),
                json_encode($notificationPayload, JSON_UNESCAPED_UNICODE)
            );
        }

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $reason = $report->getReason();
                if (
                    strpos($reason, '410') !== false
                    || strpos($reason, '403') !== false
                    || strpos($reason, 'VAPID') !== false
                ) {
                    $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')
                        ->execute([$report->getEndpoint()]);
                }
                error_log('[CHAT PUSH WEB FAIL] ' . $reason);
            }
        }
    }

    if ($androidTokens) {
        qt_chat_push_send_android($pdo, $androidTokens, $notificationPayload);
    }
}

function qt_chat_push_should_notify_user(PDO $pdo, int $userId): bool
{
    try {
        // Check if user has an active session and if the website is focused
        $stmt = $pdo->prepare('
            SELECT last_activity, is_focused 
            FROM user_focus_state 
            WHERE user_id = ? 
            ORDER BY last_activity DESC 
            LIMIT 1
        ');
        $stmt->execute([$userId]);
        $focusState = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$focusState) {
            // No focus state recorded, assume user should be notified
            error_log('[FOCUS CHECK] No focus state for user ' . $userId . ' - allowing notification');
            return true;
        }
        
        $lastActivity = strtotime($focusState['last_activity']);
        $isFocused = (bool)$focusState['is_focused'];
        $isRecentlyActive = (time() - $lastActivity) < 30; // 30 seconds threshold
        
        error_log('[FOCUS CHECK] User ' . $userId . ' - focused: ' . ($isFocused ? 'YES' : 'NO') . ', recently active: ' . ($isRecentlyActive ? 'YES' : 'NO') . ', last activity: ' . $focusState['last_activity']);
        
        // Don't notify if website is open and focused
        if ($isRecentlyActive && $isFocused) {
            error_log('[FOCUS CHECK] User ' . $userId . ' - BLOCKING notification (website is focused)');
            return false;
        }
        
        // Notify if website is closed or not focused
        error_log('[FOCUS CHECK] User ' . $userId . ' - ALLOWING notification (website not focused or not active)');
        return true;
        
    } catch (Throwable $e) {
        // If there's any error (table doesn't exist, etc.), default to allowing notifications
        error_log('[FOCUS CHECK ERROR] ' . $e->getMessage() . ' - allowing notification');
        return true;
    }
}

function qt_chat_push_check_batching(PDO $pdo, int $userId, array $payload): ?array
{
    try {
        $chatIdentifier = qt_chat_push_extract_chat_identifier($payload);
        if (!$chatIdentifier) {
            return null;
        }
        
        // Check if the user is currently active and reading messages
        // If they are, don't batch notifications as they're likely reading them
        $focusStmt = $pdo->prepare('
            SELECT is_focused, last_activity 
            FROM user_focus_state 
            WHERE user_id = ? 
            ORDER BY last_activity DESC 
            LIMIT 1
        ');
        $focusStmt->execute([$userId]);
        $focusState = $focusStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($focusState) {
            $lastActivity = strtotime($focusState['last_activity']);
            $isFocused = (bool)$focusState['is_focused'];
            $isRecentlyActive = (time() - $lastActivity) < 30;
            
            // If user is focused and active, don't batch (they're likely reading messages)
            if ($isRecentlyActive && $isFocused) {
                return null;
            }
        }
        
        // Check for recent notifications from the same chat (only count unread ones)
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as count, MAX(created_at) as last_notification
            FROM notification_batch_tracker 
            WHERE user_id = ? AND chat_identifier = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            AND is_read = 0
        ');
        $stmt->execute([$userId, $chatIdentifier]);
        $batchInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $unreadCount = (int)($batchInfo['count'] ?? 0);
        
        // Record this notification as unread
        $insertStmt = $pdo->prepare('
            INSERT INTO notification_batch_tracker (user_id, chat_identifier, is_read, created_at) 
            VALUES (?, ?, 0, NOW())
        ');
        $insertStmt->execute([$userId, $chatIdentifier]);
        
        // If this is the 4th+ unread message, create a batched notification
        if ($unreadCount >= 3) {
            $contactName = qt_chat_push_extract_contact_name($payload);
            return [
                'title' => 'QuillTalk',
                'body' => "You missed " . ($unreadCount + 1) . " messages from " . $contactName,
                'icon' => $payload['icon'] ?? 'https://quilltalk.org/images/default-profile.png',
                'url' => $payload['url'] ?? ''
            ];
        }
        
        return null;
        
    } catch (Throwable $e) {
        // If there's any error (table doesn't exist, etc.), don't batch - send normal notification
        error_log('[BATCH CHECK ERROR] ' . $e->getMessage());
        return null;
    }
}

function qt_chat_push_extract_chat_identifier(array $payload): ?string
{
    $body = $payload['body'] ?? '';
    $title = $payload['title'] ?? '';
    
    // For group messages: "Someone to GroupName: message"
    if (preg_match('/^(.+?) to (.+?): /', $body, $matches)) {
        return 'group:' . $matches[2];
    }
    
    // For direct messages: title is the sender name
    if ($title !== 'QuillTalk' && $title !== '') {
        return 'direct:' . $title;
    }
    
    return null;
}

function qt_chat_push_extract_contact_name(array $payload): string
{
    $body = $payload['body'] ?? '';
    $title = $payload['title'] ?? '';
    
    // For group messages: "Someone to GroupName: message"
    if (preg_match('/^(.+?) to (.+?): /', $body, $matches)) {
        return $matches[2]; // Group name
    }
    
    // For direct messages: title is the sender name
    if ($title !== 'QuillTalk' && $title !== '') {
        return $title; // Sender name
    }
    
    return 'Unknown';
}
