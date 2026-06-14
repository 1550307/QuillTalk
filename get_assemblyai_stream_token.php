<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

function qt_stream_token_respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function qt_load_assemblyai_api_key(): string
{
    $serverAssemblyApiKey = isset($_SERVER['ASSEMBLYAI_API_KEY']) ? (string)$_SERVER['ASSEMBLYAI_API_KEY'] : '';
    $serverAssemblyCompatKey = isset($_SERVER['ASSEMBLY_API_KEY']) ? (string)$_SERVER['ASSEMBLY_API_KEY'] : '';

    return trim((string)(
        getenv('ASSEMBLYAI_API_KEY')
        ?: getenv('ASSEMBLY_API_KEY')
        ?: $serverAssemblyApiKey
        ?: $serverAssemblyCompatKey
    ));
}

$rawInput = file_get_contents('php://input');
$json = is_string($rawInput) && trim($rawInput) !== ''
    ? json_decode($rawInput, true)
    : null;
$input = is_array($json) ? $json : $_POST;

$token = trim((string)($input['token'] ?? ''));
if ($token === '') {
    qt_stream_token_respond(['success' => false, 'error' => 'Missing token'], 400);
}

$sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    qt_stream_token_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$assemblyaiApiKey = qt_load_assemblyai_api_key();
if ($assemblyaiApiKey === '') {
    qt_stream_token_respond([
        'success' => false,
        'error' => 'AssemblyAI is not configured on this server.',
    ], 503);
}

$requestUrl = 'https://streaming.assemblyai.com/v3/token?expires_in_seconds=300&max_session_duration_seconds=900';
$ch = curl_init($requestUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: ' . $assemblyaiApiKey,
    ],
    CURLOPT_TIMEOUT => 20,
]);

$responseBody = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    qt_stream_token_respond([
        'success' => false,
        'error' => 'Unable to reach AssemblyAI: ' . $curlError,
    ], 502);
}

$payload = json_decode((string)$responseBody, true);
if ($httpCode !== 200 || !is_array($payload) || empty($payload['token'])) {
    error_log('[assemblyai stream token] HTTP ' . $httpCode . ' response: ' . (string)$responseBody);
    qt_stream_token_respond([
        'success' => false,
        'error' => 'AssemblyAI did not return a valid streaming token.',
    ], 502);
}

qt_stream_token_respond([
    'success' => true,
    'token' => (string)$payload['token'],
]);
