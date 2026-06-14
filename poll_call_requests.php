<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/blocking.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';

if ($token === '') {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    echo json_encode([]);
    exit;
}
$user_id = (int)$session['user_id'];

// Return only very recent pending requests to avoid stale rings
$fetch = $pdo->prepare("
    SELECT cr.id, cr.caller_id, cr.callee_id, cr.status, cr.created_at,
           u.username,
           COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
           u.profile_pic
    FROM call_requests cr
    JOIN users u ON cr.caller_id = u.id
    WHERE cr.callee_id = ? 
      AND cr.status = 'pending'
      AND cr.created_at >= (NOW() - INTERVAL 90 SECOND)
      AND NOT EXISTS (
          SELECT 1
          FROM user_blocks ub
          WHERE (ub.blocker_id = cr.callee_id AND ub.blocked_id = cr.caller_id)
             OR (ub.blocker_id = cr.caller_id AND ub.blocked_id = cr.callee_id)
      )
    ORDER BY cr.created_at DESC
");
$fetch->execute([$user_id]);
$rows = $fetch->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows ?: []);
