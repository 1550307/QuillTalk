<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

function qt_translate_respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function qt_translate_normalize_text(string $text): string
{
    $normalized = preg_replace('/<br\s*\/?>/iu', "\n", $text);
    $stripped = html_entity_decode(strip_tags((string)$normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $stripped = preg_replace('/\x{00A0}/u', ' ', (string)$stripped);
    $stripped = preg_replace("/\r\n?/", "\n", (string)$stripped);
    $stripped = preg_replace("/[ \t]+\n/", "\n", (string)$stripped);
    $stripped = preg_replace("/\n[ \t]+/", "\n", (string)$stripped);
    $stripped = preg_replace("/\n{3,}/", "\n\n", (string)$stripped);
    return trim((string)$stripped);
}

function qt_load_mymemory_email(): string
{
    $serverEmail = isset($_SERVER['MYMEMORY_EMAIL']) ? (string)$_SERVER['MYMEMORY_EMAIL'] : '';
    $serverContactEmail = isset($_SERVER['MYMEMORY_CONTACT_EMAIL']) ? (string)$_SERVER['MYMEMORY_CONTACT_EMAIL'] : '';

    return trim((string)(
        getenv('MYMEMORY_EMAIL')
        ?: getenv('MYMEMORY_CONTACT_EMAIL')
        ?: $serverEmail
        ?: $serverContactEmail
    ));
}

function qt_translate_request_ip(): string
{
    $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwardedFor !== '') {
        foreach (explode(',', $forwardedFor) as $candidateIp) {
            $candidateIp = trim($candidateIp);
            if ($candidateIp !== '' && filter_var($candidateIp, FILTER_VALIDATE_IP)) {
                return $candidateIp;
            }
        }
    }

    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '';
}

function qt_count_translation_matches(string $text, string $pattern): int
{
    if ($text === '' || $pattern === '') {
        return 0;
    }

    $count = preg_match_all($pattern, $text, $matches);
    return is_int($count) ? $count : 0;
}

function qt_translate_lower(string $text): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($text, 'UTF-8')
        : strtolower($text);
}

function qt_detect_cyrillic_translation_language(string $text): string
{
    $normalized = qt_translate_lower($text);
    $ukrainianScore = qt_count_translation_matches($normalized, '/[\x{0456}\x{0457}\x{0454}\x{0491}]/u');
    $russianScore = qt_count_translation_matches($normalized, '/[\x{044B}\x{044D}\x{0451}\x{044A}]/u');
    return $ukrainianScore > $russianScore ? 'uk' : 'ru';
}

function qt_detect_message_language(string $text): string
{
    if ($text === '') {
        return 'en';
    }

    if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $text)) {
        return 'ar';
    }
    if (preg_match('/[\x{0900}-\x{097F}]/u', $text)) {
        return 'hi';
    }
    if (preg_match('/[\x{3041}-\x{3096}\x{309D}-\x{309E}\x{30A1}-\x{30FA}\x{30FC}]/u', $text)) {
        return 'ja';
    }
    if (preg_match('/[\x{3131}-\x{314E}\x{314F}-\x{3163}\x{AC00}-\x{D7A3}]/u', $text)) {
        return 'ko';
    }
    if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $text)) {
        return 'zh';
    }
    if (preg_match('/[\x{0400}-\x{04FF}]/u', $text)) {
        return qt_detect_cyrillic_translation_language($text);
    }

    $normalized = qt_translate_lower($text);
    $languageHints = [
        'en' => '/\b(?:hello|thanks|thank|please|what|when|where|why|your|you|the|and|with|are|this|that)\b/u',
        'es' => '/[\x{00BF}\x{00A1}\x{00F1}]|\b(?:hola|gracias|que|como|para|estoy|eres|tengo|buenos|buenas|por|favor)\b/u',
        'fr' => '/[\x{00E0}\x{00E2}\x{00E7}\x{00E9}\x{00E8}\x{00EA}\x{00EB}\x{00EE}\x{00EF}\x{00F4}\x{00F9}\x{00FB}\x{00FC}\x{0153}]|\b(?:bonjour|merci|avec|pour|comment|quoi|est|une|pas|je|tu|vous)\b/u',
        'de' => '/[\x{00E4}\x{00F6}\x{00FC}\x{00DF}]|\b(?:hallo|danke|bitte|und|ist|nicht|ich|du|mit|fuer|wie|was)\b/u',
        'it' => '/[\x{00E0}\x{00E8}\x{00E9}\x{00EC}\x{00F2}\x{00F9}]|\b(?:ciao|grazie|come|sono|non|per|con|una|che|sei|del|della)\b/u',
        'nl' => '/\b(?:hallo|dank|alsjeblieft|niet|een|het|van|voor|met|jij|ik|wat)\b/u',
        'pl' => '/[\x{0105}\x{0107}\x{0119}\x{0142}\x{0144}\x{00F3}\x{015B}\x{017A}\x{017C}]|\b(?:jest|nie|jak|dobry)\b/u',
        'pt' => '/[\x{00E3}\x{00F5}\x{00E7}\x{00E1}\x{00E0}\x{00E2}\x{00E9}\x{00EA}\x{00ED}\x{00F3}\x{00F4}\x{00FA}]|\b(?:ola|obrigad[oa]|voce|nao|estou|para|com|uma|que|como)\b/u',
        'tr' => '/[\x{0131}\x{011F}\x{00FC}\x{015F}\x{00F6}\x{00E7}]|\b(?:merhaba|tesekkur|lutfen|nasil|icin|cok|bir|sen|ben|ve)\b/u',
    ];

    $bestLanguage = 'en';
    $bestScore = 0;
    foreach ($languageHints as $languageCode => $pattern) {
        $score = qt_count_translation_matches($normalized, $pattern);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestLanguage = $languageCode;
        }
    }

    return $bestLanguage;
}

$rawInput = file_get_contents('php://input');
$json = is_string($rawInput) && trim($rawInput) !== ''
    ? json_decode($rawInput, true)
    : null;
$input = is_array($json) ? $json : $_POST;

$token = trim((string)($input['token'] ?? ''));
$text = qt_translate_normalize_text((string)($input['text'] ?? ''));
$sourceLanguage = strtolower(trim((string)($input['source_language'] ?? '')));
$targetLanguage = strtolower(trim((string)($input['target_language'] ?? '')));

if ($token === '' || $text === '' || $targetLanguage === '') {
    qt_translate_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    qt_translate_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$allowedLanguages = [
    'ar', 'de', 'en', 'es', 'fr', 'hi', 'it', 'ja', 'ko', 'nl',
    'pl', 'pt', 'ru', 'tr', 'uk', 'zh',
];

if ($sourceLanguage === '') {
    $sourceLanguage = qt_detect_message_language($text);
}

if (!in_array($sourceLanguage, $allowedLanguages, true) || !in_array($targetLanguage, $allowedLanguages, true)) {
    qt_translate_respond(['success' => false, 'error' => 'Unsupported language'], 400);
}

if ($sourceLanguage === $targetLanguage) {
    qt_translate_respond([
        'success' => false,
        'error' => 'Pick different source and target languages.',
    ], 400);
}

if (strlen($text) > 500) {
    qt_translate_respond([
        'success' => false,
        'error' => 'MyMemory supports up to 500 bytes per translation request. Try a shorter message.',
    ], 400);
}

$queryParams = [
    'q' => $text,
    'langpair' => $sourceLanguage . '|' . $targetLanguage,
    'mt' => '1',
];

$mymemoryEmail = qt_load_mymemory_email();
if ($mymemoryEmail !== '') {
    $queryParams['de'] = $mymemoryEmail;
}

$requestIp = qt_translate_request_ip();
if ($requestIp !== '') {
    $queryParams['ip'] = $requestIp;
}

$translateUrl = 'https://api.mymemory.translated.net/get?' . http_build_query(
    $queryParams,
    '',
    '&',
    PHP_QUERY_RFC3986
);

$ch = curl_init($translateUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_TIMEOUT => 25,
]);

$responseBody = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    qt_translate_respond([
        'success' => false,
        'error' => 'Unable to reach MyMemory: ' . $curlError,
    ], 502);
}

$payload = json_decode((string)$responseBody, true);
$translatedText = is_array($payload)
    ? trim((string)($payload['responseData']['translatedText'] ?? ''))
    : '';
$responseStatus = is_array($payload) ? (int)($payload['responseStatus'] ?? 0) : 0;
$responseDetails = is_array($payload) ? trim((string)($payload['responseDetails'] ?? '')) : '';
$quotaFinished = is_array($payload) ? !empty($payload['quotaFinished']) : false;

if ($quotaFinished) {
    $quotaMessage = $mymemoryEmail !== ''
        ? 'MyMemory says this server has used its free translation quota for today. Try again tomorrow or use a different contact email.'
        : 'MyMemory says this server has used its free translation quota for today. Set MYMEMORY_EMAIL to raise the free limit from 5,000 to 50,000 chars/day.';
    qt_translate_respond([
        'success' => false,
        'error' => $quotaMessage,
    ], 429);
}

if ($httpCode !== 200 || !is_array($payload) || $responseStatus !== 200 || $translatedText === '') {
    error_log('[translate_message] HTTP ' . $httpCode . ' response: ' . (string)$responseBody);
    qt_translate_respond([
        'success' => false,
        'error' => $responseDetails !== '' ? $responseDetails : 'MyMemory could not translate this message right now.',
    ], 502);
}

qt_translate_respond([
    'success' => true,
    'translated_text' => $translatedText,
    'detected_language' => $sourceLanguage,
]);
