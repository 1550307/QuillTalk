<?php
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => $_SERVER['HTTP_HOST'], 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/poll_auth.php';

header('Content-Type: application/json');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$userId = qt_poll_require_user_id($pdo);
if (!$userId) {
    respond(['success' => false, 'error' => qt_poll_auth_error_message($pdo)], 401);
}

$optionId = (int)($_GET['option_id'] ?? 0);
if (!$optionId) {
    respond(['success' => false, 'error' => 'Missing option_id'], 400);
}

try {
    // Get voters for this option
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
            u.username,
            u.profile_pic,
            CASE WHEN COALESCE(u.online, 0) = 1 THEN 'online' ELSE 'offline' END AS online_status,
            u.last_seen_at AS last_seen
        FROM poll_votes pv
        JOIN users u ON pv.user_id = u.id
        WHERE pv.option_id = ?
        ORDER BY pv.voted_at DESC
    ");
    $stmt->execute([$optionId]);
    $voters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond([
        'success' => true,
        'voters' => $voters
    ]);

} catch (Exception $e) {
    error_log('Get poll option voters error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to get voters'], 500);
}
