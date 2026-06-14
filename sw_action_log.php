<?php
// Lightweight log endpoint for Service Worker action diagnostics
// Writes JSON-lines to sw_action_debug.log for quick inspection.

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$entry = [
    'ts' => time(),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'payload' => $data ?? $raw
];
@file_put_contents(__DIR__ . '/sw_action_debug.log', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
// Respond with no content to keep the SW flow simple
http_response_code(204);
