<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Debug what parameters are being received
$debug_info = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'post_data' => $_POST,
    'raw_input' => file_get_contents('php://input'),
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown',
    'token_received' => isset($_POST['token']) ? 'yes' : 'no',
    'token_length' => isset($_POST['token']) ? strlen($_POST['token']) : 0,
    'message_id_received' => isset($_POST['message_id']) ? 'yes' : 'no',
    'message_id_value' => $_POST['message_id'] ?? 'not_set',
    'scope_received' => isset($_POST['scope']) ? 'yes' : 'no',
    'scope_value' => $_POST['scope'] ?? 'not_set',
];

echo json_encode($debug_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>