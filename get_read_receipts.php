<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

$token  = trim((string)($_GET['token'] ?? ''));
$target = qt_parse_chat_target($_GET['with'] ?? '');

if ($token === '' || $target['type'] === 'unknown' || $target['id'] <= 0) {
    echo json_encode([]); exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) { echo json_encode([]); exit; }
$userId = (int)$session['user_id'];

if ($target['type'] === 'group') {
    if (!qt_user_can_access_group($pdo, $userId, $target['id'])) { echo json_encode([]); exit; }

    $rStmt = $pdo->prepare("
        SELECT cgm.user_id, cgm.last_seen_message_id,
               COALESCE(NULLIF(u.display_name,''),u.username) AS display_name,
               COALESCE(NULLIF(u.profile_pic,''),'images/default-profile.png') AS profile_pic,
               COALESCE(u.online,0) AS online
        FROM chat_group_members cgm
        JOIN users u ON u.id = cgm.user_id
        WHERE cgm.group_id = ? AND cgm.user_id != ?
          AND cgm.last_seen_message_id > 0
    ");
    $rStmt->execute([$target['id'], $userId]);
} else {
    /* Peer's read checkpoint lives on friends WHERE user_id = peer AND friend_id = viewer */
    $rStmt = $pdo->prepare("
        SELECT f.user_id, f.last_seen_message_id,
               COALESCE(NULLIF(u.display_name,''),u.username) AS display_name,
               COALESCE(NULLIF(u.profile_pic,''),'images/default-profile.png') AS profile_pic,
               COALESCE(u.online,0) AS online
        FROM friends f
        JOIN users u ON u.id = f.user_id
        WHERE f.user_id = ? AND f.friend_id = ?
          AND f.last_seen_message_id > 0
    ");
    $rStmt->execute([$target['id'], $userId]);
}

$rows = $rStmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
