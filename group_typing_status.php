<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (function_exists('ensure_group_typing_schema')) {
        ensure_group_typing_schema($pdo);
    }

    $token = trim((string)($_REQUEST['token'] ?? ''));
    if ($token === '') {
        respond(['success' => false, 'error' => 'Missing token'], 400);
    }

    // Keep this aligned with the rest of the chat endpoints: the app-level token
    // check already controls access to the current session.
    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        respond(['success' => false, 'error' => 'Invalid session'], 401);
    }

    $userId = (int)$session['user_id'];
    $groupId = (int)($_REQUEST['group_id'] ?? 0);

    if ($groupId <= 0) {
        respond(['success' => false, 'error' => 'Invalid group_id'], 400);
    }

    // Groups live in chat_group_members; the old group_members table name rejects
    // valid groups and makes the frontend poll produce repeated 400s.
    $stmt = $pdo->prepare("
        SELECT 1
        FROM chat_group_members
        WHERE group_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$groupId, $userId]);
    if (!$stmt->fetch()) {
        respond([
            'success' => true,
            'typing_users' => []
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Update typing status
        $isTyping = ($_POST['is_typing'] ?? '0') === '1';
        
        if ($isTyping) {
            // Set typing status (expires in 5 seconds)
            $stmt = $pdo->prepare("
                INSERT INTO group_typing (group_id, user_id, last_typing_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE last_typing_at = NOW()
            ");
            $stmt->execute([$groupId, $userId]);
        } else {
            // Remove typing status
            $stmt = $pdo->prepare("DELETE FROM group_typing WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$groupId, $userId]);
        }

        respond(['success' => true]);
    } else {
        // GET request - fetch who's typing
        // Clean up old typing statuses (older than 5 seconds)
        $stmt = $pdo->prepare("DELETE FROM group_typing WHERE last_typing_at < DATE_SUB(NOW(), INTERVAL 5 SECOND)");
        $stmt->execute();

        // Get current typers (excluding current user)
        $stmt = $pdo->prepare("
            SELECT 
                gt.user_id,
                COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name
            FROM group_typing gt
            JOIN users u ON gt.user_id = u.id
            WHERE gt.group_id = ? AND gt.user_id != ?
            ORDER BY gt.last_typing_at ASC
        ");
        $stmt->execute([$groupId, $userId]);
        $typingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond([
            'success' => true,
            'typing_users' => $typingUsers
        ]);
    }
} catch (Throwable $e) {
    error_log("Group typing status error: " . $e->getMessage());
    respond(['success' => false, 'error' => 'Server error'], 500);
}
