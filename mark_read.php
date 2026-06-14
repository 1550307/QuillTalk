<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

ensure_message_metadata_schema($pdo);

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
$target = qt_parse_chat_target($_GET['with'] ?? '');

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ?");
$stmt->execute([$token]);
$user = $stmt->fetchColumn();

if (!$user || $target['type'] === 'unknown' || $target['id'] <= 0) {
    echo json_encode(['last_seen' => 0]);
    exit;
}

if ($target['type'] === 'group') {
    if (!qt_user_can_access_group($pdo, (int)$user, (int)$target['id'])) {
        echo json_encode(['last_seen' => 0]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT last_seen_message_id
        FROM chat_group_members
        WHERE user_id = ? AND group_id = ?
    ");
    $stmt->execute([$user, $target['id']]);
    $oldLastSeen = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("
        SELECT MAX(id)
        FROM group_messages
        WHERE group_id = ?
    ");
    $stmt->execute([$target['id']]);
    $maxId = $stmt->fetchColumn() ?: 0;

    $pdo->prepare("
        UPDATE chat_group_members
        SET last_seen_message_id = ?
        WHERE user_id = ? AND group_id = ?
    ")->execute([$maxId, $user, $target['id']]);
} else {
    // 1. Get the CURRENT (old) checkpoint before we update it
    $stmt = $pdo->prepare("
        SELECT last_seen_message_id 
        FROM friends 
        WHERE user_id = ? AND friend_id = ?
    ");
    $stmt->execute([$user, $target['id']]);
    $oldLastSeen = $stmt->fetchColumn() ?: 0;

    // 2. Get newest message id in this chat to set the NEW checkpoint
    $stmt = $pdo->prepare("
        SELECT MAX(id) FROM messages
        WHERE (
                COALESCE(is_ai_response, 0) = 0
                AND (
                    (sender_id = ? AND recipient_id = ?)
                    OR (sender_id = ? AND recipient_id = ?)
                )
            )
           OR (
                COALESCE(is_ai_response, 0) = 1
                AND (
                    (ai_origin_user_id = ? AND recipient_id = ?)
                    OR (ai_origin_user_id = ? AND recipient_id = ?)
                )
           )
    ");
    $stmt->execute([
        $target['id'],
        $user,
        $user,
        $target['id'],
        $target['id'],
        $user,
        $user,
        $target['id'],
    ]);
    $maxId = $stmt->fetchColumn() ?: 0;

    // 3. Update the database to the newest ID
    $pdo->prepare("
        UPDATE friends
        SET last_seen_message_id = ?
        WHERE user_id = ? AND friend_id = ?
    ")->execute([$maxId, $user, $target['id']]);
}

// 4. ✅ Return the OLD value to JS so it can place the divider
echo json_encode(['last_seen' => $oldLastSeen]);
exit;
