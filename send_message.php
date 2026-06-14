<?php
// ================= HARD SAFETY =================
declare(strict_types=1);
ob_start();
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/push_debug.log');
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// ================= HEADERS =================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ================= REQUIRE =================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/blocking.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/chat_push.php';

ensure_message_metadata_schema($pdo);

// WebPush is optional — only load if the vendor directory exists
$_qt_autoload = __DIR__ . '/quilltalk-backend/vendor/autoload.php';
if (is_file($_qt_autoload)) {
    require $_qt_autoload;
}
unset($_qt_autoload);

const ATTACHMENT_PREFIX = '__ATTACHMENT__:';
const MAX_ATTACHMENT_SIZE = 15 * 1024 * 1024;

// ================= HELPER =================
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize_file_name(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name));
    $safe = trim((string)$safe, '._-');
    if ($safe === '') {
        return 'file';
    }
    return substr($safe, 0, 120);
}

function normalize_attachment_waveform(mixed $rawValue): array {
    if (!is_string($rawValue) || trim($rawValue) === '') {
        return [];
    }
    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return [];
    }
    $normalized = [];
    foreach ($decoded as $sample) {
        if (!is_numeric($sample)) {
            continue;
        }
        $value = (float)$sample;
        if (!is_finite($value)) {
            continue;
        }
        $normalized[] = max(0.0, min(1.0, round($value, 3)));
        if (count($normalized) >= 48) {
            break;
        }
    }
    return $normalized;
}

function get_attachment_push_text(string $storedMessage): string {
    if (!str_starts_with($storedMessage, ATTACHMENT_PREFIX)) {
        return $storedMessage;
    }
    $payload = json_decode(substr($storedMessage, strlen(ATTACHMENT_PREFIX)), true);
    if (!is_array($payload)) {
        return 'Sent an attachment';
    }
    $kind = $payload['kind'] ?? 'file';
    $name = trim((string)($payload['name'] ?? ''));
    if ($kind === 'image') return 'Sent a photo';
    if ($kind === 'video') return 'Sent a video';
    if ($kind === 'audio') return 'Sent a voice message';
    if ($name !== '') return 'Sent a file: ' . $name;
    return 'Sent an attachment';
}

function resolve_firebase_service_account_path(): ?string {
    $envPath = trim((string)(getenv('QUILLTALK_FIREBASE_SERVICE_ACCOUNT') ?: ''));
    $candidates = array_filter([
        $envPath,
        __DIR__ . '/firebase-service-account.json',
    ]);

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function load_firebase_project_id(?string $serviceAccountPath): string {
    if ($serviceAccountPath === null || !is_file($serviceAccountPath)) {
        return '';
    }

    $raw = file_get_contents($serviceAccountPath);
    $json = json_decode((string)$raw, true);
    return trim((string)($json['project_id'] ?? ''));
}

function get_or_create_ai_user_id(PDO $pdo): int {
    // First try to find existing AI user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR display_name = ? LIMIT 1");
    $stmt->execute(['quilltalk_ai', 'QuillTalk AI']);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        $aiUserId = (int)$existingUser['id'];
        error_log('[AI USER DEBUG] Found existing AI user with ID: ' . $aiUserId);
        return $aiUserId;
    }
    
    // Create AI user if it doesn't exist
    try {
        error_log('[AI USER DEBUG] Creating new AI user...');
        $insertStmt = $pdo->prepare("
            INSERT INTO users (username, display_name, email, password_hash, bio, profile_pic, created_at, is_passkey_user)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 0)
        ");
        
        $username = 'quilltalk_ai';
        $displayName = 'QuillTalk AI';
        $email = 'ai@quilltalk.internal';
        $passwordHash = password_hash('ai_system_account_' . bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $bio = 'I am QuillTalk AI, your helpful assistant built into the messaging platform.';
        $profilePic = 'images/default-profile.png'; // Use default profile image
        
        $insertStmt->execute([
            $username,
            $displayName, 
            $email,
            $passwordHash,
            $bio,
            $profilePic
        ]);
        
        $aiUserId = (int)$pdo->lastInsertId();
        error_log('[AI USER DEBUG] Created new AI user with ID: ' . $aiUserId);
        
        // Verify the user was created correctly
        $verifyStmt = $pdo->prepare("SELECT id, username, display_name FROM users WHERE id = ?");
        $verifyStmt->execute([$aiUserId]);
        $verifyUser = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        if ($verifyUser) {
            error_log('[AI USER DEBUG] Verified AI user - ID: ' . $verifyUser['id'] . ', username: ' . $verifyUser['username'] . ', display_name: ' . $verifyUser['display_name']);
        }
        
        return $aiUserId;
    } catch (Exception $e) {
        error_log('[AI USER CREATION ERROR] ' . $e->getMessage());
        // Fallback: return a default system user ID or create a simple one
        return 1; // Fallback to user ID 1 if creation fails
    }
}

function qt_ai_response_acquire_origin_lock(PDO $pdo, string $scope, int $originMessageId, int $timeoutSeconds = 15): string {
    if ($originMessageId <= 0) {
        return '';
    }

    $normalizedScope = preg_replace('/[^a-z0-9:_-]+/i', '_', strtolower(trim($scope)));
    if (!is_string($normalizedScope) || $normalizedScope === '') {
        $normalizedScope = 'message';
    }

    $lockName = 'qt_ai_reply_' . substr(sha1($normalizedScope . ':' . $originMessageId), 0, 40);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([$lockName, $timeoutSeconds]);
    $acquired = $stmt->fetchColumn();

    if ((string)$acquired !== '1') {
        throw new RuntimeException('Another AI reply is already being generated for this message.');
    }

    return $lockName;
}

function qt_ai_response_release_origin_lock(PDO $pdo, string $lockName): void {
    if ($lockName === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockName]);
    } catch (Throwable $e) {
        error_log('[AI RESPONSE LOCK RELEASE] ' . $e->getMessage());
    }
}

function qt_find_existing_ai_direct_response_id(PDO $pdo, int $originUserId, int $originMessageId, int $recipientId): int {
    if ($originUserId <= 0 || $originMessageId <= 0 || $recipientId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM messages
        WHERE COALESCE(is_ai_response, 0) = 1
          AND COALESCE(ai_origin_user_id, 0) = ?
          AND COALESCE(ai_origin_message_id, 0) = ?
          AND recipient_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$originUserId, $originMessageId, $recipientId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function qt_find_existing_ai_group_response_id(PDO $pdo, int $originMessageId, int $groupId): int {
    if ($originMessageId <= 0 || $groupId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM group_messages
        WHERE COALESCE(is_ai_response, 0) = 1
          AND COALESCE(ai_origin_message_id, 0) = ?
          AND group_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$originMessageId, $groupId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function send_android_message_pushes(PDO $pdo, array $deviceTokens, array $payload): array {
    if (!$deviceTokens) {
        return ['success' => false, 'skipped' => 'no_android_tokens'];
    }

    if (!class_exists('\Google\Client')) {
        return ['success' => false, 'skipped' => 'google_client_missing'];
    }

    $serviceAccountPath = resolve_firebase_service_account_path();
    $projectId = load_firebase_project_id($serviceAccountPath);

    if ($serviceAccountPath === null || $projectId === '') {
        error_log('[ANDROID MESSAGE PUSH] Missing firebase-service-account.json or project_id');
        return ['success' => false, 'skipped' => 'missing_firebase_service_account'];
    }

    $client = new \Google\Client();
    $client->setAuthConfig($serviceAccountPath);
    $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
    $httpClient = $client->authorize();
    $deleteStmt = $pdo->prepare("DELETE FROM android_push_tokens WHERE fcm_token = ?");

    $sent = 0;
    $failed = 0;

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
            $sent++;
        } catch (Throwable $e) {
            $failed++;
            $message = $e->getMessage();
            error_log('[ANDROID MESSAGE PUSH FAIL] ' . $message);
            if (
                stripos($message, 'UNREGISTERED') !== false
                || stripos($message, 'registration-token-not-registered') !== false
            ) {
                $deleteStmt->execute([(string)$deviceToken]);
            }
        }
    }

    return [
        'success' => $sent > 0,
        'sent' => $sent,
        'failed' => $failed,
    ];
}

// ================= INPUT =================
$token           = $_POST['token'] ?? '';
$recipientTarget = qt_parse_chat_target($_POST['recipient_id'] ?? '');
$message         = trim($_POST['message'] ?? '');
$attachment      = $_FILES['attachment'] ?? null;
$hasAttachment   = is_array($attachment) && (($attachment['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
$attachmentWaveform = normalize_attachment_waveform($_POST['attachment_waveform'] ?? null);
$attachmentDuration = isset($_POST['attachment_duration']) && is_numeric($_POST['attachment_duration'])
    ? max(0.0, min(3600.0, (float)$_POST['attachment_duration']))
    : 0.0;

$replyToId = isset($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : 0;
$forwardFromUserId = isset($_POST['forward_from_user_id']) ? (int)$_POST['forward_from_user_id'] : 0;
$forwardFromDisplayName = trim((string)($_POST['forward_from_display_name'] ?? ''));

// AI Response handling
$isAiResponse = isset($_POST['is_ai_response']) && $_POST['is_ai_response'] === '1';
$aiSenderDisplayName = trim((string)($_POST['sender_display_name'] ?? ''));
$originalUserId = isset($_POST['original_user_id']) ? (int)$_POST['original_user_id'] : 0;
$originMessageId = isset($_POST['origin_message_id']) ? (int)$_POST['origin_message_id'] : 0;

// Debug logging for AI responses
if ($isAiResponse) {
    error_log('[AI RESPONSE DEBUG] Processing AI response - sender_display_name: ' . $aiSenderDisplayName . ', message length: ' . strlen($message) . ', original_user_id: ' . $originalUserId);
    error_log('[AI RESPONSE DEBUG] POST data: ' . json_encode($_POST));
} else {
    error_log('[AI RESPONSE DEBUG] Not an AI response - is_ai_response: ' . ($_POST['is_ai_response'] ?? 'not set'));
}

if ($token === '' || $recipientTarget['type'] === 'unknown' || $recipientTarget['id'] === 0 || ($message === '' && !$hasAttachment)) {
    error_log('[send_message] Missing parameters - token: ' . ($token === '' ? 'empty' : 'present') . 
              ', recipientTarget type: ' . $recipientTarget['type'] . 
              ', recipientTarget id: ' . $recipientTarget['id'] . 
              ', message: ' . ($message === '' ? 'empty' : 'present') . 
              ', hasAttachment: ' . ($hasAttachment ? 'yes' : 'no') .
              ', recipient_id param: ' . ($_POST['recipient_id'] ?? 'not set'));
    respond(['success' => false, 'error' => 'Missing parameters', 'debug' => [
        'recipient_type' => $recipientTarget['type'],
        'recipient_id' => $recipientTarget['id'],
        'has_message' => $message !== '',
        'has_attachment' => $hasAttachment
    ]], 400);
}

// ================= SESSION =================
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

// Handle AI responses - use AI user ID instead of session user ID
if ($isAiResponse && $aiSenderDisplayName === 'QuillTalk AI') {
    $sender_id = get_or_create_ai_user_id($pdo);
    error_log('[AI RESPONSE DEBUG] Using AI user ID: ' . $sender_id);
} else {
    $sender_id = (int)$user['user_id'];
    error_log('[AI RESPONSE DEBUG] Using session user ID: ' . $sender_id);
}

$recipient_id = $recipientTarget['type'] === 'direct' ? (int)$recipientTarget['id'] : 0;
$group_id     = $recipientTarget['type'] === 'group'  ? (int)$recipientTarget['id'] : 0;
$ai_chat_id   = $recipientTarget['type'] === 'ai'     ? (int)$recipientTarget['id'] : 0;
$recipient_id = $recipientTarget['type'] === 'direct' ? (int)$recipientTarget['id'] : 0;
$group_id     = $recipientTarget['type'] === 'group'  ? (int)$recipientTarget['id'] : 0;
$ai_chat_id   = $recipientTarget['type'] === 'ai'     ? (int)$recipientTarget['id'] : 0;

if ($recipientTarget['type'] === 'group') {
    // Skip group membership check for AI responses
    if (!$isAiResponse) {
        error_log('[AI RESPONSE DEBUG] Performing group membership check for sender_id: ' . $sender_id . ', group_id: ' . $group_id);
        $groupSendState = qt_get_group_send_state($pdo, $sender_id, $group_id);
        if (empty($groupSendState['allowed'])) {
            error_log('[AI RESPONSE DEBUG] Group membership check failed: ' . json_encode($groupSendState));
            respond([
                'success'     => false,
                'error'       => (string)($groupSendState['error'] ?? 'Group chat not found'),
                'muted_until' => $groupSendState['muted_until'] ?? null,
            ], 403);
        }
    } else {
        error_log('[AI RESPONSE DEBUG] Skipping group membership check for AI response - sender_id: ' . $sender_id . ', group_id: ' . $group_id);
    }
} else {
    // ================= FRIENDSHIP CHECK =================
    // Skip friendship check for AI responses
    if (!$isAiResponse) {
        error_log('[AI RESPONSE DEBUG] Performing friendship check for sender_id: ' . $sender_id . ', recipient_id: ' . $recipient_id);
        $check = $pdo->prepare("
            SELECT 1 FROM friends
            WHERE (user_id = ? AND friend_id = ?)
               OR (user_id = ? AND friend_id = ?)
            LIMIT 1
        ");
        $check->execute([$sender_id, $recipient_id, $recipient_id, $sender_id]);

        if (!$check->fetch()) {
            respond(['success' => false, 'error' => 'Not friends'], 403);
        }

        $blockRelationship = qt_get_block_relationship($pdo, $sender_id, $recipient_id);
        if (!empty($blockRelationship['viewer_has_blocked'])) {
            respond(['success' => false, 'error' => 'You cannot send messages to users you have blocked.'], 403);
        }
        if (!empty($blockRelationship['blocked_viewer'])) {
            respond(['success' => false, 'error' => 'You cannot send messages to users who have blocked you.'], 403);
        }
    } else {
        error_log('[AI RESPONSE DEBUG] Skipping friendship check for AI response');
    }
}

if ($forwardFromUserId > 0 && $forwardFromDisplayName === '') {
    respond(['success' => false, 'error' => 'Invalid forward metadata'], 400);
}

if ($replyToId > 0 && $forwardFromUserId > 0) {
    respond(['success' => false, 'error' => 'Cannot combine reply and forward'], 400);
}

if ($replyToId > 0) {
    if ($recipientTarget['type'] === 'group') {
        $chk = $pdo->prepare("SELECT id FROM group_messages WHERE id = ? AND group_id = ? LIMIT 1");
        $chk->execute([$replyToId, $group_id]);
        if (!$chk->fetch()) {
            respond(['success' => false, 'error' => 'Invalid reply reference'], 400);
        }
    } else {
        $chk = $pdo->prepare("
            SELECT id FROM messages WHERE id = ?
              AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
            LIMIT 1
        ");
        $chk->execute([$replyToId, $sender_id, $recipient_id, $recipient_id, $sender_id]);
        if (!$chk->fetch()) {
            respond(['success' => false, 'error' => 'Invalid reply reference'], 400);
        }
    }
}

if ($hasAttachment) {
    if (($attachment['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(['success' => false, 'error' => 'Upload failed'], 400);
    }

    $fileSize = (int)($attachment['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > MAX_ATTACHMENT_SIZE) {
        respond(['success' => false, 'error' => 'File is too large'], 400);
    }

    $originalName     = sanitize_file_name((string)($attachment['name'] ?? 'file'));
    $extension        = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $blockedExtensions = ['php', 'phtml', 'phar', 'cgi', 'pl', 'js', 'html', 'htm', 'svg'];
    if ($extension !== '' && in_array($extension, $blockedExtensions, true)) {
        respond(['success' => false, 'error' => 'Unsupported file type'], 400);
    }

    $allowedExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif',
        'pdf', 'txt', 'csv',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', 'rar', '7z',
        'mp3', 'wav', 'ogg', 'm4a',
        'mp4', 'mov', 'webm'
    ];

    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        respond(['success' => false, 'error' => 'Unsupported file type'], 400);
    }

    $tmpPath     = (string)($attachment['tmp_name'] ?? '');
    $clientMime  = trim((string)($attachment['type'] ?? ''));
    $finfo       = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $tmpPath !== '' ? (string)$finfo->file($tmpPath) : 'application/octet-stream';

    $audioExtensions    = ['mp3', 'wav', 'ogg', 'm4a'];
    $imageExtensions    = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'];
    $videoExtensions    = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'ogv', 'mpeg', 'mpg', 'mpe', '3gp', '3g2'];
    $isVoiceMessageUpload = ($attachmentDuration > 0) || ($attachmentWaveform !== []);
    $isImageUpload = str_starts_with($detectedMime, 'image/')
        || (str_starts_with($clientMime, 'image/') && in_array($extension, $imageExtensions, true));
    $isAudioUpload = str_starts_with($detectedMime, 'audio/')
        || str_starts_with($clientMime, 'audio/')
        || in_array($extension, $audioExtensions, true)
        || ($extension === 'webm' && ($isVoiceMessageUpload || str_starts_with($clientMime, 'audio/')));
    $isVideoUpload = str_starts_with($detectedMime, 'video/')
        || str_starts_with($clientMime, 'video/')
        || (
            in_array($extension, $videoExtensions, true)
            && !$isVoiceMessageUpload
            && !str_starts_with($clientMime, 'audio/')
            && !str_starts_with($detectedMime, 'audio/')
        );

    if ($isImageUpload) {
        $kind = 'image';
        $mime = str_starts_with($detectedMime, 'image/') ? $detectedMime : ($clientMime !== '' ? $clientMime : $detectedMime);
    } elseif ($isAudioUpload) {
        $kind = 'audio';
        $mime = str_starts_with($clientMime, 'audio/') ? $clientMime : ($detectedMime !== '' ? $detectedMime : 'audio/webm');
    } elseif ($isVideoUpload) {
        $kind = 'video';
        $mime = str_starts_with($detectedMime, 'video/') ? $detectedMime : ($clientMime !== '' ? $clientMime : 'video/mp4');
    } else {
        $kind = 'file';
        $mime = $detectedMime !== '' ? $detectedMime : ($clientMime !== '' ? $clientMime : 'application/octet-stream');
    }

    if ($mime === 'image/svg+xml' || $clientMime === 'image/svg+xml' || $detectedMime === 'image/svg+xml') {
        respond(['success' => false, 'error' => 'Unsupported file type'], 400);
    }

    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'chat';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        respond(['success' => false, 'error' => 'Could not save file'], 500);
    }

    $storedName         = bin2hex(random_bytes(16)) . '.' . $extension;
    $storedRelativePath = 'uploads/chat/' . $storedName;
    $storedAbsolutePath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($tmpPath, $storedAbsolutePath)) {
        respond(['success' => false, 'error' => 'Could not save file'], 500);
    }

    $payload = [
        'kind' => $kind,
        'url'  => $storedRelativePath,
        'name' => $originalName,
        'mime' => $mime,
        'size' => $fileSize,
    ];

    if ($kind === 'audio') {
        if ($attachmentDuration > 0) {
            $payload['duration'] = round($attachmentDuration, 2);
        }
        if ($attachmentWaveform !== []) {
            $payload['waveform'] = $attachmentWaveform;
        }
    }

    if ($message !== '') {
        $payload['caption'] = $message;
    }

    $message = ATTACHMENT_PREFIX . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ================= INSERT MESSAGE =================
$fwdUidInsert = $forwardFromUserId > 0 ? $forwardFromUserId : null;
$fwdNameInsert = $forwardFromUserId > 0 ? $forwardFromDisplayName : null;
$aiResponseInsert = $isAiResponse ? 1 : 0;
$aiSenderDisplayNameInsert = $isAiResponse && $aiSenderDisplayName !== '' ? $aiSenderDisplayName : null;
$aiOriginUserInsert = (
    $isAiResponse
    && $recipientTarget['type'] === 'direct'
    && ($originalUserId > 0 || !empty($user['user_id']))
)
    ? (int)($originalUserId > 0 ? $originalUserId : $user['user_id'])
    : null;
$aiOriginMessageIdInsert = $isAiResponse && $originMessageId > 0 ? $originMessageId : null;
$replyInsert = $replyToId > 0
    ? $replyToId
    : ($aiOriginMessageIdInsert !== null ? $aiOriginMessageIdInsert : null);
$msg_id = 0;
$aiResponseLockName = '';

try {
    error_log('[AI RESPONSE DEBUG] Inserting message - recipient_type: ' . $recipientTarget['type'] . ', sender_id: ' . $sender_id . ', recipient_id: ' . $recipient_id . ', group_id: ' . $group_id);

    if ($isAiResponse && $aiOriginMessageIdInsert !== null) {
        if ($recipientTarget['type'] === 'group') {
            $aiResponseLockName = qt_ai_response_acquire_origin_lock($pdo, 'group_message', $aiOriginMessageIdInsert);
            $msg_id = qt_find_existing_ai_group_response_id($pdo, $aiOriginMessageIdInsert, $group_id);
        } elseif ($recipientTarget['type'] === 'direct' && $aiOriginUserInsert !== null) {
            $aiResponseLockName = qt_ai_response_acquire_origin_lock($pdo, 'direct_message', $aiOriginMessageIdInsert);
            $msg_id = qt_find_existing_ai_direct_response_id($pdo, $aiOriginUserInsert, $aiOriginMessageIdInsert, $recipient_id);
        }
    }

    if ($msg_id <= 0) {
        try {
            if ($recipientTarget['type'] === 'group') {
                $insert = $pdo->prepare("
                    INSERT INTO group_messages (
                        group_id,
                        sender_id,
                        message,
                        reply_to_id,
                        forward_from_user_id,
                        forward_from_display_name,
                        is_ai_response,
                        ai_sender_display_name,
                        ai_origin_message_id,
                        created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert->execute([
                    $group_id,
                    $sender_id,
                    $message,
                    $replyInsert,
                    $fwdUidInsert,
                    $fwdNameInsert,
                    $aiResponseInsert,
                    $aiSenderDisplayNameInsert,
                    $aiOriginMessageIdInsert,
                ]);
            } elseif ($recipientTarget['type'] === 'ai') {
                $insert = $pdo->prepare("
                    INSERT INTO messages (
                        sender_id,
                        recipient_id,
                        message,
                        reply_to_id,
                        forward_from_user_id,
                        forward_from_display_name,
                        is_ai_response,
                        ai_sender_display_name,
                        ai_origin_user_id,
                        ai_origin_message_id,
                        created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert->execute([
                    $sender_id,
                    $ai_chat_id,
                    $message,
                    $replyInsert,
                    $fwdUidInsert,
                    $fwdNameInsert,
                    $aiResponseInsert,
                    $aiSenderDisplayNameInsert,
                    null,
                    null,
                ]);
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO messages (
                        sender_id,
                        recipient_id,
                        message,
                        reply_to_id,
                        forward_from_user_id,
                        forward_from_display_name,
                        is_ai_response,
                        ai_sender_display_name,
                        ai_origin_user_id,
                        ai_origin_message_id,
                        created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert->execute([
                    $sender_id,
                    $recipient_id,
                    $message,
                    $replyInsert,
                    $fwdUidInsert,
                    $fwdNameInsert,
                    $aiResponseInsert,
                    $aiSenderDisplayNameInsert,
                    $aiOriginUserInsert,
                    $aiOriginMessageIdInsert,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[send_message INSERT] ' . $e->getMessage());
            ensure_message_metadata_schema($pdo);

            if ($recipientTarget['type'] === 'group') {
                $insert = $pdo->prepare("
                    INSERT INTO group_messages (
                        group_id,
                        sender_id,
                        message,
                        reply_to_id,
                        forward_from_user_id,
                        forward_from_display_name,
                        is_ai_response,
                        ai_sender_display_name,
                        ai_origin_message_id,
                        created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert->execute([
                    $group_id,
                    $sender_id,
                    $message,
                    $replyInsert,
                    $fwdUidInsert,
                    $fwdNameInsert,
                    $aiResponseInsert,
                    $aiSenderDisplayNameInsert,
                    $aiOriginMessageIdInsert,
                ]);
            } elseif ($recipientTarget['type'] === 'ai') {
                $insert = $pdo->prepare("
                    INSERT INTO messages (
                        sender_id,
                        recipient_id,
                        message,
                        reply_to_id,
                        forward_from_user_id,
                        forward_from_display_name,
                        is_ai_response,
                        ai_sender_display_name,
                        ai_origin_user_id,
                        ai_origin_message_id,
                        created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert->execute([
                    $sender_id,
                    $ai_chat_id,
                    $message,
                    $replyInsert,
                    $fwdUidInsert,
                    $fwdNameInsert,
                    $aiResponseInsert,
                    $aiSenderDisplayNameInsert,
                    null,
                    null,
                ]);
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO messages (
                        sender_id,
                        recipient_id,
                        message,
                        reply_to_id,
                        forward_from_user_id,
                        forward_from_display_name,
                        is_ai_response,
                        ai_sender_display_name,
                        ai_origin_user_id,
                        ai_origin_message_id,
                        created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert->execute([
                    $sender_id,
                    $recipient_id,
                    $message,
                    $replyInsert,
                    $fwdUidInsert,
                    $fwdNameInsert,
                    $aiResponseInsert,
                    $aiSenderDisplayNameInsert,
                    $aiOriginUserInsert,
                    $aiOriginMessageIdInsert,
                ]);
            }
        }

        $msg_id = (int)$pdo->lastInsertId();
        error_log('[AI RESPONSE DEBUG] Message inserted successfully');
    } else {
        error_log('[AI RESPONSE DEBUG] Reusing existing AI response with ID: ' . $msg_id);
    }
} catch (Throwable $e) {
    error_log('[send_message INSERT retry] ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Could not save message. If this continues, ask the host to add columns to the messages tables.'], 500);
} finally {
    qt_ai_response_release_origin_lock($pdo, $aiResponseLockName);
}

if ($recipientTarget['type'] === 'direct') try {
    $typingDirs = [
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profiles',
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads',
    ];
    foreach ($typingDirs as $typingDir) {
        $typingPath = $typingDir . DIRECTORY_SEPARATOR . 'typing_' . $sender_id . '_' . $recipient_id . '.json';
        if (is_file($typingPath)) {
            @unlink($typingPath);
        }
    }
} catch (Throwable $e) {
    error_log('[TYPING CLEAR ERROR] ' . $e->getMessage());
}

// ================= FETCH MESSAGE & SENDER INFO =================
$fetch = $pdo->prepare($recipientTarget['type'] === 'group'
    ? "
        SELECT
            gm.id,
            gm.message,
            gm.created_at,
            gm.sender_id,
            NULL AS recipient_id,
            gm.group_id,
            u.username,
            COALESCE(NULLIF(gm.ai_sender_display_name, ''), NULLIF(u.display_name, ''), u.username) AS display_name,
            u.profile_pic,
            COALESCE(NULLIF(sender_chat_nick.nickname, ''), '') AS sender_chat_nickname,
            gm.reply_to_id,
            gm.forward_from_user_id,
            gm.forward_from_display_name,
            COALESCE(gm.is_ai_response, 0) AS is_ai_response,
            NULL AS original_user_id,
            CONCAT('" . QT_GROUP_CHAT_PREFIX . "', gm.group_id) AS chat_key,
            rm.id AS reply_to_ref_id,
            COALESCE(NULLIF(ru.display_name, ''), ru.username) AS reply_to_display_name,
            rm.message AS reply_to_message_body
        FROM group_messages gm
        JOIN users u ON gm.sender_id = u.id
        LEFT JOIN chat_user_nicknames sender_chat_nick
            ON sender_chat_nick.user_id = gm.sender_id
           AND sender_chat_nick.chat_type = 'group'
           AND sender_chat_nick.chat_id = gm.group_id
        LEFT JOIN group_messages rm
            ON gm.reply_to_id = rm.id AND rm.group_id = gm.group_id
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE gm.id = ?
    "
    : "
        SELECT
            m.id,
            m.message,
            m.created_at,
            m.sender_id,
            m.recipient_id,
            NULL AS group_id,
            u.username,
            COALESCE(NULLIF(m.ai_sender_display_name, ''), NULLIF(u.display_name, ''), u.username) AS display_name,
            u.profile_pic,
            COALESCE(NULLIF(sender_chat_nick.nickname, ''), '') AS sender_chat_nickname,
            m.reply_to_id,
            m.forward_from_user_id,
            m.forward_from_display_name,
            COALESCE(m.is_ai_response, 0) AS is_ai_response,
            m.ai_origin_user_id AS original_user_id,
            CAST(
                CASE
                    WHEN COALESCE(m.is_ai_response, 0) = 1 AND m.ai_origin_user_id IS NOT NULL THEN
                        CASE
                            WHEN m.ai_origin_user_id = ? THEN m.recipient_id
                            ELSE m.ai_origin_user_id
                        END
                    ELSE
                        CASE
                            WHEN m.sender_id = ? THEN m.recipient_id
                            ELSE m.sender_id
                        END
                END AS CHAR
            ) AS chat_key,
            rm.id AS reply_to_ref_id,
            COALESCE(NULLIF(ru.display_name, ''), ru.username) AS reply_to_display_name,
            rm.message AS reply_to_message_body
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        LEFT JOIN chat_user_nicknames sender_chat_nick
            ON sender_chat_nick.user_id = m.sender_id
           AND sender_chat_nick.chat_type = 'direct'
           AND sender_chat_nick.chat_id = m.recipient_id
        LEFT JOIN messages rm ON m.reply_to_id = rm.id AND (
            (rm.sender_id = ? AND rm.recipient_id = ?) OR (rm.sender_id = ? AND rm.recipient_id = ?)
        )
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE m.id = ?
    "
);
if ($recipientTarget['type'] === 'group') {
    $fetch->execute([$msg_id]);
} else {
    $fetch->execute([
        (int)$user['user_id'],
        (int)$user['user_id'],
        $sender_id,
        $recipient_id,
        $recipient_id,
        $sender_id,
        $msg_id,
    ]);
}
$msg = $fetch->fetch(PDO::FETCH_ASSOC);

// ================= PUSH NOTIFICATION =================
try {
    $senderDisplayName = trim((string)($msg['display_name'] ?? $msg['username'] ?? ''));
    $iconPath = trim((string)($msg['profile_pic'] ?? 'images/default-profile.png'));
    $fullIconUrl = 'https://quilltalk.org/' . ltrim($iconPath !== '' ? $iconPath : 'images/default-profile.png', '/');

    if ($recipientTarget['type'] === 'group') {
        $groupStmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(name, ''), CONCAT('Group ', id)) AS group_name
            FROM chat_groups
            WHERE id = ?
            LIMIT 1
        ");
        $groupStmt->execute([$group_id]);
        $groupName = (string)($groupStmt->fetchColumn() ?: ('Group ' . $group_id));

        $memberStmt = $pdo->prepare("
            SELECT
                member.user_id,
                u.username,
                COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
                " . qt_user_online_sql('u') . " AS online,
                COALESCE(NULLIF(member_chat_nick.nickname, ''), '') AS chat_nickname,
                COALESCE(NULLIF(pref.notify_mode, ''), ?) AS notification_mode
            FROM chat_group_members member
            JOIN users u
                ON u.id = member.user_id
            LEFT JOIN chat_notification_preferences pref
                ON pref.user_id = member.user_id
               AND pref.chat_type = 'group'
               AND pref.chat_id = member.group_id
            LEFT JOIN chat_user_nicknames member_chat_nick
                ON member_chat_nick.user_id = member.user_id
               AND member_chat_nick.chat_type = 'group'
               AND member_chat_nick.chat_id = member.group_id
            WHERE member.group_id = ?
              AND member.user_id <> ?
        ");
        $memberStmt->execute([QT_CHAT_NOTIFY_ALL, $group_id, $sender_id]);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($members as $member) {
            $mode = qt_normalize_chat_notification_mode((string)($member['notification_mode'] ?? QT_CHAT_NOTIFY_ALL));
            $mentioned = qt_message_mentions_user(
                (string)$msg['message'],
                (string)($member['username'] ?? ''),
                (string)($member['display_name'] ?? ''),
                (string)($member['chat_nickname'] ?? ''),
                !empty($member['online'])
            );
            if ($mode === QT_CHAT_NOTIFY_MENTION && !$mentioned) {
                continue;
            }

            $payload = qt_chat_push_build_group_payload(
                (string)$msg['message'],
                $senderDisplayName !== '' ? $senderDisplayName : 'Someone',
                $groupName,
                $fullIconUrl,
                $mode === QT_CHAT_NOTIFY_MENTION
            );
            qt_chat_push_send_to_user($pdo, (int)($member['user_id'] ?? 0), $payload);
        }
    } else {
        $recipientStmt = $pdo->prepare("
            SELECT
                u.username,
                COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
                COALESCE(NULLIF(chat_nick.nickname, ''), '') AS chat_nickname
            FROM users u
            LEFT JOIN chat_user_nicknames chat_nick
                ON chat_nick.user_id = u.id
               AND chat_nick.chat_type = 'direct'
               AND chat_nick.chat_id = ?
            WHERE u.id = ?
            LIMIT 1
        ");
        $recipientStmt->execute([$sender_id, $recipient_id]);
        $recipientIdentity = $recipientStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $mode = qt_get_chat_notification_mode($pdo, $recipient_id, 'direct', $sender_id);
        $mentioned = qt_message_mentions_user(
            (string)$msg['message'],
            (string)($recipientIdentity['username'] ?? ''),
            (string)($recipientIdentity['display_name'] ?? ''),
            (string)($recipientIdentity['chat_nickname'] ?? '')
        );

        if ($mode !== QT_CHAT_NOTIFY_MENTION || $mentioned) {
            $payload = qt_chat_push_build_direct_payload(
                (string)$msg['message'],
                $senderDisplayName !== '' ? $senderDisplayName : 'QuillTalk',
                $fullIconUrl,
                $mode === QT_CHAT_NOTIFY_MENTION
            );
            qt_chat_push_send_to_user($pdo, $recipient_id, $payload);
        }
    }
} catch (Throwable $e) {
    error_log('[PUSH ERROR] ' . $e->getMessage());
}

// ================= FINAL RESPONSE =================
error_log('[AI RESPONSE DEBUG] Sending final response - message ID: ' . $msg['id'] . ', display_name: ' . $msg['display_name']);

respond([
    'success' => true,
    'message' => [
        'id'                         => $msg['id'],
        'sender_id'                  => $msg['sender_id'] ?? $sender_id,
        'recipient_id'               => $msg['recipient_id'] ?? ($recipientTarget['type'] === 'direct' ? $recipient_id : null),
        'group_id'                   => $msg['group_id'] ?? null,
        'self'                       => $recipientTarget['type'] === 'group'
            ? (int)($sender_id === (int)$user['user_id'])
            : (int)(!$isAiResponse && $sender_id === (int)$user['user_id']),
        'username'                   => $msg['username'],
        'display_name'               => $msg['display_name'],
        'sender_display_name'        => $msg['display_name'],
        'sender_profile_pic'         => $msg['profile_pic'] ?? 'images/default-profile.png',
        'sender_chat_nickname'       => $msg['sender_chat_nickname'] ?? '',
        'chat_key'                   => $msg['chat_key'] ?? ($recipientTarget['type'] === 'group' ? (QT_GROUP_CHAT_PREFIX . $group_id) : (string)$recipient_id),
        'is_ai_response'             => (int)($msg['is_ai_response'] ?? $aiResponseInsert),
        'original_user_id'           => $msg['original_user_id'] ?? $aiOriginUserInsert,
        'message'                    => $msg['message'],
        'created_at'                 => $msg['created_at'],
        'reply_to_id'                => $msg['reply_to_id'] ?? null,
        'forward_from_user_id'       => $msg['forward_from_user_id'] ?? null,
        'forward_from_display_name'  => $msg['forward_from_display_name'] ?? null,
        'reply_to_ref_id'            => $msg['reply_to_ref_id'] ?? null,
        'reply_to_display_name'      => $msg['reply_to_display_name'] ?? null,
        'reply_to_message_body'      => $msg['reply_to_message_body'] ?? null,
    ]
]);
