<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/blocking.php';
require __DIR__ . '/includes/history_events.php';

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$json = is_string($rawInput) && trim($rawInput) !== ''
    ? json_decode($rawInput, true)
    : null;
$input = is_array($json) ? $json : $_POST;

$token = trim((string)($input['token'] ?? ''));
$targetUserId = (int)($input['target_user_id'] ?? 0);
$action = trim((string)($input['action'] ?? ''));

if ($token === '' || $targetUserId <= 0 || !in_array($action, ['block', 'unblock'], true)) {
    respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$viewerId = (int)($session['user_id'] ?? 0);
if ($viewerId <= 0 || $viewerId === $targetUserId) {
    respond(['success' => false, 'error' => 'Invalid target user'], 400);
}

$targetStmt = $pdo->prepare("
    SELECT id, username, COALESCE(NULLIF(display_name, ''), username) AS display_name
    FROM users
    WHERE id = ?
    LIMIT 1
");
$targetStmt->execute([$targetUserId]);
$target = $targetStmt->fetch(PDO::FETCH_ASSOC);

if (!$target) {
    respond(['success' => false, 'error' => 'User not found'], 404);
}

$existingRelationship = qt_get_block_relationship($pdo, $viewerId, $targetUserId);
$wasBlocked = !empty($existingRelationship['viewer_has_blocked']);

if ($action === 'block') {
    $stmt = $pdo->prepare("
        INSERT INTO user_blocks (blocker_id, blocked_id, created_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE created_at = created_at
    ");
    $stmt->execute([$viewerId, $targetUserId]);

    try {
        $pdo->prepare("
            UPDATE call_requests
            SET status = 'rejected'
            WHERE status = 'pending'
              AND (
                  (caller_id = ? AND callee_id = ?)
                  OR
                  (caller_id = ? AND callee_id = ?)
              )
        ")->execute([$viewerId, $targetUserId, $targetUserId, $viewerId]);
    } catch (Throwable $e) {
        // Safe to ignore when call_requests has not been created yet.
    }
} else {
    $stmt = $pdo->prepare("
        DELETE FROM user_blocks
        WHERE blocker_id = ? AND blocked_id = ?
    ");
    $stmt->execute([$viewerId, $targetUserId]);
}

$didChange = ($action === 'block' && !$wasBlocked)
    || ($action === 'unblock' && $wasBlocked);

if ($didChange) {
    try {
        qt_log_history_event($pdo, [
            'actor_user_id' => $viewerId,
            'subject_user_id' => $targetUserId,
            'event_type' => $action,
        ]);
    } catch (Throwable $e) {
        error_log('[block_user history] ' . $e->getMessage());
    }
}

$relationship = qt_get_block_relationship($pdo, $viewerId, $targetUserId);

respond([
    'success' => true,
    'action' => $action,
    'target' => [
        'id' => $targetUserId,
        'username' => (string)($target['username'] ?? ''),
        'display_name' => (string)($target['display_name'] ?? ''),
    ],
    'relationship' => $relationship,
]);
