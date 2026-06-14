<?php
declare(strict_types=1);
ob_start();
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/transcribe_debug.log');
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require __DIR__ . '/includes/db.php';

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $_POST['token'] ?? '';
$audioUrl = $_POST['audio_url'] ?? '';

if ($token === '' || $audioUrl === '') {
    respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

// Verify session
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

// Get the full path to the audio file
$audioPath = __DIR__ . '/' . ltrim($audioUrl, '/');

if (!file_exists($audioPath)) {
    respond(['success' => false, 'error' => 'Audio file not found'], 404);
}

// Use AssemblyAI for transcription (Free tier: 5 hours/month)
// Get API key from https://www.assemblyai.com/
// Try both ASSEMBLYAI_API_KEY and ASSEMBLY_API_KEY for compatibility
$assemblyaiApiKey = getenv('ASSEMBLYAI_API_KEY') ?: getenv('ASSEMBLY_API_KEY') ?: $_SERVER['ASSEMBLYAI_API_KEY'] ?? $_SERVER['ASSEMBLY_API_KEY'] ?? '';

if (empty($assemblyaiApiKey)) {
    // Fallback: return a placeholder message if API key is not configured
    respond([
        'success' => true,
        'transcription' => '[Transcription service not configured. Please set ASSEMBLYAI_API_KEY or ASSEMBLY_API_KEY environment variable to enable speech-to-text. Get a free API key at https://www.assemblyai.com/]'
    ]);
}

try {
    // Step 1: Upload the audio file to AssemblyAI
    $uploadUrl = 'https://api.assemblyai.com/v2/upload';
    
    $audioContent = file_get_contents($audioPath);
    if ($audioContent === false) {
        respond(['success' => false, 'error' => 'Unable to read audio file'], 500);
    }
    
    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $assemblyaiApiKey,
            'Content-Type: application/octet-stream',
            'Transfer-Encoding: chunked'
        ],
        CURLOPT_POSTFIELDS => $audioContent,
        CURLOPT_TIMEOUT => 60
    ]);
    
    $uploadResponse = curl_exec($ch);
    $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log('[transcribe] Upload cURL error: ' . $curlError);
        respond(['success' => false, 'error' => 'Network error: ' . $curlError], 500);
    }
    
    if ($uploadHttpCode !== 200) {
        error_log('[transcribe] Upload error: HTTP ' . $uploadHttpCode . ' - ' . $uploadResponse);
        respond(['success' => false, 'error' => 'Upload error: HTTP ' . $uploadHttpCode . ' - ' . $uploadResponse], 500);
    }
    
    $uploadResult = json_decode($uploadResponse, true);
    
    if (!isset($uploadResult['upload_url'])) {
        error_log('[transcribe] Invalid upload response: ' . $uploadResponse);
        respond(['success' => false, 'error' => 'Invalid upload response: ' . $uploadResponse], 500);
    }
    
    $audioUploadUrl = $uploadResult['upload_url'];
    
    // Step 2: Request transcription
    $transcribeUrl = 'https://api.assemblyai.com/v2/transcript';
    
    $requestBody = json_encode([
        'audio_url' => $audioUploadUrl,
        'speech_models' => ['universal-2']  // Use universal-2 model (available on free tier)
    ]);
    
    $ch = curl_init($transcribeUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $assemblyaiApiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $transcribeResponse = curl_exec($ch);
    $transcribeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($transcribeHttpCode !== 200) {
        error_log('[transcribe] Transcribe request error: HTTP ' . $transcribeHttpCode . ' - Request: ' . $requestBody . ' - Response: ' . $transcribeResponse);
        respond(['success' => false, 'error' => 'Transcription request error: ' . $transcribeResponse], 500);
    }
    
    $transcribeResult = json_decode($transcribeResponse, true);
    
    if (!isset($transcribeResult['id'])) {
        error_log('[transcribe] Invalid transcribe response: ' . $transcribeResponse);
        respond(['success' => false, 'error' => 'Invalid transcription response: ' . $transcribeResponse], 500);
    }
    
    $transcriptId = $transcribeResult['id'];
    
    // Step 3: Poll for completion (max 60 seconds)
    $maxAttempts = 60;
    $attempt = 0;
    $pollUrl = 'https://api.assemblyai.com/v2/transcript/' . $transcriptId;
    
    while ($attempt < $maxAttempts) {
        sleep(1);
        $attempt++;
        
        $ch = curl_init($pollUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $assemblyaiApiKey
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $pollResponse = curl_exec($ch);
        $pollHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($pollHttpCode !== 200) {
            error_log('[transcribe] Poll error at attempt ' . $attempt . ': HTTP ' . $pollHttpCode);
            continue;
        }
        
        $pollResult = json_decode($pollResponse, true);
        
        if (isset($pollResult['status'])) {
            if ($pollResult['status'] === 'completed') {
                $transcriptionText = $pollResult['text'] ?? '[No transcription available]';
                if (empty(trim($transcriptionText))) {
                    $transcriptionText = '[Audio was silent or could not be transcribed]';
                }
                respond([
                    'success' => true,
                    'transcription' => $transcriptionText
                ]);
            } elseif ($pollResult['status'] === 'error') {
                $errorMsg = $pollResult['error'] ?? 'Unknown error';
                error_log('[transcribe] Transcription failed: ' . $errorMsg);
                respond(['success' => false, 'error' => 'Transcription failed: ' . $errorMsg], 500);
            }
            // If status is 'queued' or 'processing', continue polling
        }
    }
    
    // Timeout
    respond(['success' => false, 'error' => 'Transcription timeout - audio may be too long or service is slow'], 500);
    
} catch (Throwable $e) {
    error_log('[transcribe] Exception: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}
