<?php
declare(strict_types=1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/push_debug.log');
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

error_log('[TEST LOGGING] This is a test log entry at ' . date('Y-m-d H:i:s'));
error_log('[TEST LOGGING] Log file path: ' . __DIR__ . '/push_debug.log');
error_log('[TEST LOGGING] File exists: ' . (file_exists(__DIR__ . '/push_debug.log') ? 'YES' : 'NO'));
error_log('[TEST LOGGING] File writable: ' . (is_writable(__DIR__ . '/push_debug.log') ? 'YES' : 'NO'));

echo json_encode([
    'success' => true,
    'message' => 'Test logging executed',
    'timestamp' => date('Y-m-d H:i:s'),
    'log_file' => __DIR__ . '/push_debug.log',
    'file_exists' => file_exists(__DIR__ . '/push_debug.log'),
    'file_writable' => is_writable(__DIR__ . '/push_debug.log')
]);
?>