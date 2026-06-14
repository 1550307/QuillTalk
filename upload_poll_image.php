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

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    respond(['success' => false, 'error' => 'No file uploaded'], 400);
}

$file = $_FILES['file'];
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$maxSize = 5 * 1024 * 1024; // 5MB

if (!in_array($file['type'], $allowedTypes)) {
    respond(['success' => false, 'error' => 'Invalid file type'], 400);
}

if ($file['size'] > $maxSize) {
    respond(['success' => false, 'error' => 'File too large (max 5MB)'], 400);
}

try {
    $uploadDir = __DIR__ . '/uploads/polls/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = uniqid('poll_', true) . '.' . $ext;
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        respond(['success' => false, 'error' => 'Failed to save file'], 500);
    }

    respond([
        'success' => true,
        'url' => 'uploads/polls/' . $filename
    ]);

} catch (Exception $e) {
    error_log('Upload poll image error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Upload failed'], 500);
}
