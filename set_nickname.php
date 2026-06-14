<?php
@ini_set('display_errors', '0');
@error_reporting(0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/history_events.php';
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'DB connection failed']));
}

$rawInput = @file_get_contents('php://input');
$input = @json_decode($rawInput, true);

if (!$input || !is_array($input)) {
    die(json_encode(['success' => false, 'error' => 'Invalid input']));
}

$token = isset($input['token']) ? trim($input['token']) : '';
$target_user_id = isset($input['target_user_id']) ? trim($input['target_user_id']) : '';
$nickname = isset($input['nickname']) ? $input['nickname'] : '';

if (empty($token) || empty($target_user_id)) {
    die(json_encode(['success' => false, 'error' => 'Missing fields']));
}

try {
    // Get user_id from session token
    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session || !isset($session['user_id'])) {
        die(json_encode(['success' => false, 'error' => 'Invalid token']));
    }
    
    $user_id = $session['user_id'];
    $normalizedNickname = is_string($nickname) ? trim($nickname) : '';
    
    // Check if table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'user_nicknames'");
    if (!$tableCheck || !$tableCheck->fetchColumn()) {
        die(json_encode(['success' => false, 'error' => 'Nicknames feature not available']));
    }

    $existingStmt = $pdo->prepare("
        SELECT nickname
        FROM user_nicknames
        WHERE user_id = ? AND target_user_id = ?
        LIMIT 1
    ");
    $existingStmt->execute([$user_id, $target_user_id]);
    $previousNickname = trim((string)($existingStmt->fetchColumn() ?: ''));
    
    if ($normalizedNickname !== '') {
        // Insert or update nickname
        $stmt = $pdo->prepare("
            INSERT INTO user_nicknames (user_id, target_user_id, nickname) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE nickname = VALUES(nickname), updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$user_id, $target_user_id, $normalizedNickname]);
    } else {
        // Delete nickname
        $stmt = $pdo->prepare("DELETE FROM user_nicknames WHERE user_id = ? AND target_user_id = ?");
        $stmt->execute([$user_id, $target_user_id]);
    }

    if ($normalizedNickname !== '' && $normalizedNickname !== $previousNickname) {
        try {
            qt_log_history_event($pdo, [
                'actor_user_id' => (int)$user_id,
                'subject_user_id' => (int)$target_user_id,
                'event_type' => 'nickname_set',
                'event_value' => $normalizedNickname,
            ]);
        } catch (Throwable $e) {
            error_log('[set_nickname history] ' . $e->getMessage());
        }
    } elseif ($normalizedNickname === '' && $previousNickname !== '') {
        try {
            qt_log_history_event($pdo, [
                'actor_user_id' => (int)$user_id,
                'subject_user_id' => (int)$target_user_id,
                'event_type' => 'nickname_removed',
                'event_value' => $previousNickname,
            ]);
        } catch (Throwable $e) {
            error_log('[set_nickname history] ' . $e->getMessage());
        }
    }
    
    die(json_encode(['success' => true]));
    
} catch (Exception $e) {
    @error_log('set_nickname error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Server error']));
}
