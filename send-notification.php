<?php

declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/quilltalk-backend/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

header('Content-Type: text/plain; charset=utf-8');

// DEBUG: Kill the script and show us exactly what the PHP sees
$stmtTest = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
$stmtTest->execute([$title]);
$pic = $stmtTest->fetchColumn();

die(json_encode([
    'debug_title' => $title,
    'debug_pic_from_db' => $pic,
    'debug_full_url' => $baseUrl . $pic,
    'instruction' => 'If you do not see this message in your Network tab, you are editing the wrong file.'
]));

$data = json_decode(file_get_contents('php://input'), true);

$user_id = (int)($data['user_id'] ?? 0);
$title   = (string)($data['title'] ?? 'Notification');
$body    = trim((string)($data['body'] ?? ''));
$url     = (string)($data['url'] ?? '/');

if (!$user_id || $body === '') {
    http_response_code(400);
    exit('Missing parameters');
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . "/";

// 1. Get the pic from DB
$stmtIcon = $pdo->prepare("SELECT profile_pic FROM users WHERE LOWER(username) = LOWER(TRIM(?))");
$stmtIcon->execute([$title]);
$senderPic = $stmtIcon->fetchColumn();

// 2. Decide the URL (No file_exists check, it's safer for now)
if ($senderPic) {
    // If example A (uploads/profiles/img.jpg), this makes: https://site.com/uploads/profiles/img.jpg
    $iconUrl = $baseUrl . ltrim($senderPic, '/');
} else {
    $iconUrl = $baseUrl . "images/default-profile.png";
}

// 3. BUILD THE ARRAY FIRST
$payloadData = [
    'title' => $title,
    'body'  => $body,
    'url'   => $url,
    'icon'  => $iconUrl // This is now GUARANTEED to be in the array
];

// 4. NOW encode that specific array
$payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);

foreach ($subs as $s) {
    $subscription = Subscription::create([
        'endpoint' => $s['endpoint'],
        'keys'     => [
            'p256dh' => $s['p256dh'],
            'auth'   => $s['auth'],
        ],
    ]);

    $webPush->queueNotification(
        $subscription,
        $payload,
        [
            'TTL'     => 60,             // Required for Android
            'urgency' => 'high',         // Helps delivery
            'topic'   => 'chat-message', // Avoids collapse bugs
        ]
    );
}

/* Flush + log failures */
foreach ($webPush->flush() as $report) {
    if (!$report->isSuccess()) {
        error_log('[PUSH FAIL] ' . $report->getReason());
    }
}

echo 'OK';