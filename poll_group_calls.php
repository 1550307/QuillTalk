<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

$sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    echo json_encode([]);
    exit;
}

$userId = (int)($session['user_id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT gc.id
    FROM group_calls gc
    JOIN group_call_members gcm
      ON gcm.call_id = gc.id
     AND gcm.user_id = ?
    WHERE gc.group_id > 0
      AND gcm.invite_status = ?
      AND gc.status IN (?, ?)
      AND gc.created_at >= (NOW() - INTERVAL 2 HOUR)
    ORDER BY gc.created_at DESC, gc.id DESC
    LIMIT 5
");
$stmt->execute([
    $userId,
    QT_GROUP_CALL_MEMBER_PENDING,
    QT_GROUP_CALL_STATUS_RINGING,
    QT_GROUP_CALL_STATUS_ACTIVE
]);
$callIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$requests = [];
foreach ($callIds as $callIdRaw) {
    $details = qt_fetch_group_call_details($pdo, $userId, (int)$callIdRaw);
    if (!$details) {
        continue;
    }
    if (($details['call']['viewer_status'] ?? '') !== QT_GROUP_CALL_MEMBER_PENDING) {
        continue;
    }
    if (($details['call']['status'] ?? '') === QT_GROUP_CALL_STATUS_ENDED) {
        continue;
    }
    $requests[] = [
        'id' => (int)$details['call']['id'],
        'group_id' => (int)$details['call']['group_id'],
        'initiator_id' => (int)$details['call']['initiator_id'],
        'initiator_display_name' => (string)($details['call']['initiator_display_name'] ?? ''),
        'initiator_profile_pic' => (string)($details['call']['initiator_profile_pic'] ?? 'images/default-profile.png'),
        'group_name' => (string)($details['group']['display_name'] ?? ''),
        'group_icon' => (string)($details['group']['profile_pic'] ?? QT_DEFAULT_GROUP_CHAT_ICON),
        'group_key' => (string)($details['group']['id'] ?? qt_build_group_chat_key((int)$details['call']['group_id'])),
        'created_at' => (string)($details['call']['created_at'] ?? ''),
        'other_participants' => array_values(array_filter(
            array_map(fn($p) => [
                'user_id' => (int)$p['user_id'],
                'display_name' => (string)($p['display_name'] ?? ''),
            ], $details['participants'] ?? []),
            fn($p) => $p['user_id'] !== $userId && $p['user_id'] !== (int)($details['call']['initiator_id'] ?? 0)
        )),
    ];
}

echo json_encode($requests, JSON_UNESCAPED_UNICODE);
