<?php
// microsoft-callback.php
declare(strict_types=1);
ini_set('display_errors',1);
error_reporting(E_ALL);

// ensure cookie/session settings same as login.php
$cookieOpts = [
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => 'quilltalk.org',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'None'
];
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params($cookieOpts);
} else {
    session_set_cookie_params(
        $cookieOpts['lifetime'],
        $cookieOpts['path'],
        $cookieOpts['domain'],
        $cookieOpts['secure'],
        $cookieOpts['httponly']
    );
}
session_start();

function dbg_write(array $d) : void {
    $f = __DIR__ . '/ms_debug.log';
    $s = "[" . date('Y-m-d H:i:s') . "] " . json_encode($d, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($f, $s, FILE_APPEND | LOCK_EX);
}

dbg_write(['event'=>'callback-enter','_GET'=>$_GET,'session_id'=>session_id(),'session_state'=>($_SESSION['ms_oauth_state'] ?? null),'cookie_sid'=>($_COOKIE[session_name()] ?? null)]);

require_once __DIR__ . '/includes/db.php';

// Microsoft credentials (same as login.php)
$clientId     = '11cd13ae-d98d-402a-b5b2-950e21dabd15';
$clientSecret = 'qSI8Q~RSdIVnxyrBzPNT6HBEJBoP6m-wgGgsnczU';
$redirectUri  = 'https://quilltalk.org/microsoft-callback.php';

// 1) ensure code and state present
if (!isset($_GET['code']) || !isset($_GET['state'])) {
    dbg_write(['event'=>'callback-missing-code-or-state','_GET'=>$_GET]);
    die('Invalid callback: missing code or state. Check ms_debug.log in web root.');
}

// 2) validate state matches session
if (!isset($_SESSION['ms_oauth_state']) || $_SESSION['ms_oauth_state'] !== $_GET['state']) {
    dbg_write(['event'=>'callback-state-mismatch','session_state'=>($_SESSION['ms_oauth_state'] ?? null),'get_state'=>$_GET['state']]);
    die('State mismatch. Session state: ' . ($_SESSION['ms_oauth_state'] ?? 'NONE') . ' | GET state: ' . ($_GET['state'] ?? 'NONE') . '. See ms_debug.log');
}

// 3) exchange code for token via cURL
$tokenUrl = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token';
$post = http_build_query([
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'code'          => $_GET['code'],
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code'
]);

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true,
]);
$resp = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

dbg_write(['event'=>'token-response','resp'=>substr($resp,0,1000),'curlErr'=>$curlErr]);

$token = json_decode($resp, true);
if (!isset($token['access_token'])) {
    dbg_write(['event'=>'token-failed','response'=>$resp]);
    die('Token exchange failed. See ms_debug.log');
}

// 4) fetch profile
$ch2 = curl_init('https://graph.microsoft.com/v1.0/me');
curl_setopt_array($ch2, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token']],
    CURLOPT_RETURNTRANSFER => true
]);
$profileRaw = curl_exec($ch2);
$curlErr2 = curl_error($ch2);
curl_close($ch2);
dbg_write(['event'=>'profile-response','profile_raw'=>substr($profileRaw,0,1000),'curlErr'=>$curlErr2]);

$profile = json_decode($profileRaw, true);
$email = $profile['userPrincipalName'] ?? null;
$display = $profile['displayName'] ?? ('ms-user-' . bin2hex(random_bytes(6)));

if (!$email) {
    dbg_write(['event'=>'no-email','profile'=>$profile]);
    die('Microsoft did not return an email. See ms_debug.log');
}

// 5) create user if needed (PDO)
$stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
$userId = $existingUser['id'] ?? false;

if (!$userId) {
    $username = generate_unique_username($pdo, (string)$display);
    $ins = $pdo->prepare("
        INSERT INTO users (email, username, display_name, password_hash, bio, created_at)
        VALUES (?, ?, ?, '', '', NOW())
    ");
    $ins->execute([$email, $username, $display]);
    $userId = (int)$pdo->lastInsertId();
    dbg_write(['event'=>'created-user','email'=>$email,'userId'=>$userId]);
} else {
    if (empty($existingUser['display_name'])) {
        $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?")->execute([$display, $userId]);
    }
    dbg_write(['event'=>'found-user','email'=>$email,'userId'=>$userId]);
}

// 6) create session token expected by app.php
$appToken = bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO sessions (user_id, token) VALUES (?, ?)")->execute([$userId, $appToken]);

dbg_write(['event'=>'created-app-session','userId'=>$userId,'appToken'=>$appToken]);

// 7) redirect to app.php with token
header("Location: app.php?token=" . urlencode($appToken));
exit;
