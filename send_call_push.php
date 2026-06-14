<?php
declare(strict_types=1);
ob_start();

require __DIR__ . '/includes/db.php';

// Lightweight push debug logger (appends JSON lines to push-debug.log)
function push_log(array $entry): void {
    $fn = __DIR__ . '/push-debug.log';
    $entry['ts'] = date('c');
    // Attempt to redact very large payloads
    if (isset($entry['payload']) && is_string($entry['payload']) && strlen($entry['payload']) > 2000) {
        $entry['payload'] = substr($entry['payload'], 0, 2000) . '...';
    }
    @file_put_contents($fn, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$_qt_autoload = __DIR__ . '/quilltalk-backend/vendor/autoload.php';
if (is_file($_qt_autoload)) {
    try {
        require $_qt_autoload;
    } catch (Throwable $autoloadError) {
        push_log([
            'event' => 'composer_autoload_failure',
            'autoload' => $_qt_autoload,
            'error' => $autoloadError->getMessage(),
        ]);
        error_log('[CALL PUSH AUTOLOAD FAIL] ' . $autoloadError->getMessage());
    }
}
unset($_qt_autoload);

header('Content-Type: application/json; charset=utf-8');

function resolveFirebaseServiceAccountPath(): ?string
{
    $envPath = trim((string)(getenv('QUILLTALK_FIREBASE_SERVICE_ACCOUNT') ?: ''));
    $candidates = array_filter([
        $envPath,
        __DIR__ . '/firebase-service-account.json',
    ]);

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function loadFirebaseProjectId(?string $serviceAccountPath): string
{
    if ($serviceAccountPath === null || !is_file($serviceAccountPath)) {
        return '';
    }

    $raw = file_get_contents($serviceAccountPath);
    $json = json_decode((string)$raw, true);
    return trim((string)($json['project_id'] ?? ''));
}

function sendAndroidIncomingCallPushes(PDO $pdo, array $deviceTokens, array $payload): array
{
    if (!$deviceTokens) {
        return ['success' => false, 'skipped' => 'no_android_tokens'];
    }

    if (!class_exists('\Google\Client')) {
        return ['success' => false, 'skipped' => 'google_client_missing'];
    }

    $serviceAccountPath = resolveFirebaseServiceAccountPath();
    $projectId = loadFirebaseProjectId($serviceAccountPath);

    if ($serviceAccountPath === null || $projectId === '') {
        error_log('[ANDROID CALL PUSH] Missing firebase-service-account.json or project_id');
        return ['success' => false, 'skipped' => 'missing_firebase_service_account'];
    }

    push_log([
        'event' => 'android_push_prepare',
        'device_count' => count($deviceTokens),
        'project_id' => $projectId,
    ]);

    $client = new \Google\Client();
    $client->setAuthConfig($serviceAccountPath);
    $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
    $httpClient = $client->authorize();
    $deleteStmt = $pdo->prepare("DELETE FROM android_push_tokens WHERE fcm_token = ?");

    $sent = 0;
    $failed = 0;

    foreach ($deviceTokens as $deviceToken) {
        $fcmMessage = [
            'message' => [
                'token' => (string)$deviceToken,
                // Keep this as a data-only FCM payload so Android delivers it to
                // MyFirebaseMessagingService even when the app is closed.
                'data' => [
                    'type' => 'incoming_call',
                    'title' => (string)$payload['title'],
                    'body' => (string)$payload['body'],
                    'url' => (string)$payload['url'],
                    'call_request_id' => (string)$payload['call_request_id'],
                    'caller_id' => (string)$payload['caller_id'],
                    'caller_name' => (string)$payload['caller_name'],
                    'caller_username' => (string)$payload['caller_username'],
                    'caller_pic' => (string)$payload['caller_pic'],
                ],
                'android' => [
                    'priority' => 'HIGH',
                    'ttl' => '300s',
                ],
            ],
        ];

        try {
            $httpClient->post(
                "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
                ['json' => $fcmMessage]
            );
            $sent++;
        } catch (Throwable $e) {
            $failed++;
            $message = $e->getMessage();
            push_log([
                'event' => 'android_push_send_failure',
                'token_suffix' => substr((string)$deviceToken, -12),
                'error' => $message,
            ]);
            error_log('[ANDROID CALL PUSH FAIL] ' . $message);
            if (stripos($message, 'UNREGISTERED') !== false || stripos($message, 'registration-token-not-registered') !== false) {
                $deleteStmt->execute([(string)$deviceToken]);
            }
        }
    }

    push_log([
        'event' => 'android_push_send_summary',
        'sent' => $sent,
        'failed' => $failed,
    ]);

    return [
        'success' => $sent > 0,
        'sent' => $sent,
        'failed' => $failed,
    ];
}

$data = json_decode(file_get_contents('php://input'), true);
$token       = $data['token'] ?? '';
$caller_id   = (int)($data['caller_id'] ?? 0);
$callee_id   = (int)($data['callee_id'] ?? 0);
$call_req_id = (int)($data['call_request_id'] ?? 0);

// Log invocation
push_log([ 'event' => 'send_call_push_invoked', 'caller_id' => $caller_id, 'callee_id' => $callee_id, 'call_request_id' => $call_req_id, 'raw' => $data ]);

if ($token === '' || !$caller_id || !$callee_id || !$call_req_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Validate session
$sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
if (!$session || (int)$session['user_id'] !== $caller_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

// Caller info
$callerStmt = $pdo->prepare("
    SELECT username, COALESCE(NULLIF(display_name, ''), username) AS display_name, profile_pic
    FROM users
    WHERE id = ? LIMIT 1
");
$callerStmt->execute([$caller_id]);
$caller = $callerStmt->fetch(PDO::FETCH_ASSOC);

if (!$caller) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Caller not found']);
    exit;
}

// Push subscriptions
$subsStmt = $pdo->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
$subsStmt->execute([$callee_id]);
$subs = $subsStmt->fetchAll(PDO::FETCH_ASSOC);
$androidStmt = $pdo->prepare("SELECT fcm_token FROM android_push_tokens WHERE user_id = ?");
$androidStmt->execute([$callee_id]);
$androidTokens = array_values(array_filter(array_map(
    static fn(array $row): string => trim((string)($row['fcm_token'] ?? '')),
    $androidStmt->fetchAll(PDO::FETCH_ASSOC)
)));

// Log discovered push targets
push_log([
    'event' => 'push_targets_found',
    'callee_id' => $callee_id,
    'web_push_count' => count($subs),
    'android_count' => count($androidTokens),
]);

if (!$subs && !$androidTokens) {
    push_log([ 'event' => 'no_push_targets', 'callee_id' => $callee_id ]);
    echo json_encode(['success' => true, 'skipped' => 'no_push_targets']);
    exit;
}

// Create a short-lived session token for the callee so the app opens authenticated
$notifyToken = bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO sessions (user_id, token) VALUES (?, ?)")->execute([$callee_id, $notifyToken]);

$siteUrl = "https://quilltalk.org/";
$iconPath = !empty($caller['profile_pic']) ? $caller['profile_pic'] : "images/default-profile.png";
$fullIconUrl = $siteUrl . ltrim($iconPath, '/');

$callerLabel = (string)($caller['display_name'] ?? $caller['username'] ?? 'Unknown');

$pushPayload = [
    'type'            => 'incoming_call',
    'title'           => "Call from {$callerLabel}",
    'body'            => 'Tap to answer',
    'url'             => $siteUrl . 'app.php?token=' . $notifyToken .
                         '&incoming_call=1' .
                         '&caller_id=' . $caller_id .
                         '&caller_name=' . urlencode($callerLabel) .
                         '&caller_username=' . urlencode((string)$caller['username']) .
                         '&caller_pic=' . urlencode($caller['profile_pic'] ?? '') .
                         '&call_request_id=' . $call_req_id,
    'icon'            => $fullIconUrl,
    'call_request_id' => $call_req_id,
    'caller_id'       => $caller_id,
    'caller_name'     => $callerLabel,
    'caller_username' => $caller['username'],
    'caller_pic'      => $caller['profile_pic'] ?? ''
];

$payload = json_encode($pushPayload, JSON_UNESCAPED_UNICODE);
$androidResult = sendAndroidIncomingCallPushes($pdo, $androidTokens, $pushPayload);
$androidPushDelivered = !empty($androidResult['success']) && (int)($androidResult['sent'] ?? 0) > 0;

push_log([
    'event' => 'android_push_result',
    'callee_id' => $callee_id,
    'result' => $androidResult,
    'android_push_delivered' => $androidPushDelivered,
]);

if ($subs && class_exists('Minishlink\WebPush\WebPush') && class_exists('Minishlink\WebPush\Subscription')) {
    $webPush = new \Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject'    => 'mailto:support@quilltalk.org',
            'publicKey'  => 'BJZapSQDICQ0e02As3dIXEBGMOWGfEelQAiM8f7vE3FzgMUJu2YKB5KqW2xuolEV_5nimni2kC_Pw970TsigMZ0',
            'privateKey' => 'g0NJ1rMUNrO_QiVgDhmb2BEj3cIErtLZwLTy-UN9gpY',
        ]
    ]);

    $deleteStmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");

    foreach ($subs as $s) {
        $webPush->queueNotification(
            \Minishlink\WebPush\Subscription::create([
                'endpoint' => $s['endpoint'],
                'keys' => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
            ]),
            $payload,
            ['TTL' => 60, 'urgency' => 'high', 'topic' => 'incoming-call']
        );
    }

    $reports = $webPush->flush();
    foreach ($reports as $report) {
        try {
            $success = $report->isSuccess();
            $endpoint = method_exists($report, 'getEndpoint') ? $report->getEndpoint() : null;
            $reason = $report->getReason();
            push_log([ 'event' => 'webpush_report', 'endpoint' => $endpoint, 'success' => $success, 'reason' => $reason ]);
            if (!$success) {
                // Delete stale or mismatched subscriptions
                if (strpos((string)$reason, '410') !== false || strpos((string)$reason, '403') !== false || strpos((string)$reason, 'VAPID') !== false) {
                    try { $deleteStmt->execute([$endpoint]); } catch (Throwable $_) {}
                }
                error_log('[CALL PUSH FAIL] ' . $reason);
            }
        } catch (Throwable $e) {
            push_log([ 'event' => 'webpush_report_error', 'error' => (string)$e, 'callee_id' => $callee_id ]);
        }
    }
}

echo json_encode([
    'success' => true,
    'web_targets' => count($subs),
    'android_targets' => count($androidTokens),
    'web_push_sent' => count($subs) > 0,
    'android_result' => $androidResult,
]);
