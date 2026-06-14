<?php
// login.php - full guarded file
// MUST be uploaded as-is. No BOM, no whitespace before <?php
declare(strict_types=1);
// Kill bad cookie (no dot)
if (isset($_COOKIE['PHPSESSID'])) {
    setcookie(
        'PHPSESSID',
        '',
        [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => 'quilltalk.org', // remove non-dot cookie
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'None'
        ]
    );
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',   // blank = current host
    'secure' => true, // must match your HTTPS
    'httponly' => true,
    'samesite' => 'None'
]);
ini_set('display_errors',1);
error_reporting(E_ALL);

// --- 1. FORCE HTTPS ---
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $location = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $location);
    exit;
}

require_once __DIR__ . '/lang.php';
require_once 'includes/microsoft_config.php';
$lang = isset($_COOKIE['quill_language']) ? $_COOKIE['quill_language'] : 'en';
define('SITE_DIR', ($lang == 'ar' ? 'rtl' : 'ltr'));
header("Access-Control-Allow-Origin: *");

// ----------------- SESSION COOKIE SETTINGS -----------------
// Force explicit session cookie params so the browser will actually accept the session cookie.
// Important: must call before session_start()
$cookieOpts = [
    'lifetime' => 0,            // session cookie by default (0 = until browser close)
    'path'      => '/',
    'domain'    => '', // adjust if you test on different domain (or remove for automatic)
    'secure'    => true,            // requires https
    'httponly' => true,
    'samesite' => 'None'
];
// 1. Force a clean, new session start
session_start();

// 2. CRITICAL FIX: Manually set the cookie header to ensure SameSite=None; Secure is sent.
//    This overrides any server-level defaults.
if (session_id() !== '') {
    $session_id = session_id();
    // The explicit header tells the browser exactly what to do.
    header('Set-Cookie: ' . session_name() . '=' . $session_id . '; Path=/; Domain=quilltalk.org; Secure; HttpOnly; SameSite=None', false);
    // Note: The 'false' parameter prevents overriding previous Set-Cookie headers.

    // If you removed the 'domain' as a troubleshooting step, use this instead:
    // header('Set-Cookie: ' . session_name() . '=' . $session_id . '; Path=/; Secure; HttpOnly; SameSite=None', false);
}

// ----------------- DEBUG HELPERS -----------------
function dbg_write(array $data) : void {
    $f = __DIR__ . '/ms_debug.log';
    $s = "[" . date('Y-m-d H:i:s') . "] " . json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($f, $s, FILE_APPEND | LOCK_EX);
}

// quick diagnostics available at ?debug=1
$DEBUG = (isset($_GET['debug']) && $_GET['debug'] == '1');

// ----------------- DB -----------------
require_once __DIR__ . '/includes/db.php'; // must set $pdo (PDO)

// ----------------- MICROSOFT CONFIG -----------------
// put your real values here or in includes/microsoft_config.php
$ms_client_id         = '11cd13ae-d98d-402a-b5b2-950e21dabd15';
$ms_client_secret = 'qSI8Q~RSdIVnxyrBzPNT6HBEJBoP6m-wgGgsnczU'; // keep secret, ok here because you provided it
$ms_redirect_uri  = 'https://quilltalk.org/microsoft-callback.php';

// log important startup info
dbg_write([
    'event' => 'login.php load',
    'session_id' => session_id(),
    'has_state' => isset($_SESSION['ms_oauth_state']),
    'cookie_quill' => $_COOKIE['quill_remember'] ?? null,
    'cookie_sid' => $_COOKIE[session_name()] ?? null
]);

// ----------------- NORMAL LOGIN (POST) -----------------
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $identifier = ''; // Can be username or email
    $password = (string)($_POST['password'] ?? '');
    
    // Check if email or username was submitted
    if (isset($_POST['email'])) {
        $identifier = trim((string)($_POST['email'] ?? ''));
        $isEmail = true;
    } else if (isset($_POST['username'])) {
        $identifier = trim((string)($_POST['username'] ?? ''));
        $isEmail = false;
    }

    try {
        $row = null;
        
        if ($isEmail) {
            // Login with email
            $sth = $pdo->prepare("SELECT id, username, display_name, password_hash FROM users WHERE email = ? LIMIT 1");
            $sth->execute([$identifier]);
            $row = $sth->fetch(PDO::FETCH_ASSOC);
        } else {
            // Login with username (no display name fallback)
            $sth = $pdo->prepare("SELECT id, username, display_name, password_hash FROM users WHERE username = ? LIMIT 1");
            $sth->execute([$identifier]);
            $row = $sth->fetch(PDO::FETCH_ASSOC);
        }

        if ($row && password_verify($password, $row['password_hash'])) {

            $user_id = (int)$row['id'];       // get the ID first
            $_SESSION['user_id'] = $user_id;  // then store session
            $_SESSION['username'] = $row['username'];

            $remember = isset($_POST['remember_me']);

            // create short-lived session token
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO sessions (user_id, token) VALUES (?, ?)")
                ->execute([$user_id, $token]);
            $pdo->prepare("UPDATE users SET online = 1, last_seen_at = NOW() WHERE id = ?")
                ->execute([$user_id]);
            
            // Define options for setting cookies (inherits secure, httponly, samesite from $cookieOpts)
            $cookie_set_options = [
                'path'      => $cookieOpts['path'],
                'domain'    => $cookieOpts['domain'],
                'secure'    => $cookieOpts['secure'],
                'httponly' => $cookieOpts['httponly'],
                'samesite' => $cookieOpts['samesite'],
            ];
            
            $expiry_one_year = time() + 365 * 24 * 60 * 60;
            
            if ($remember) {
                // A) USER CHECKED 'REMEMBER ME' (Needs persistence)
                
                // i. Generate/store persistent token in DB
                $rememberToken = bin2hex(random_bytes(32));
                $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")
                    ->execute([$rememberToken, $user_id]);

                // ii. Set 'quill_remember' cookie (long-lived)
                setcookie(
                    'quill_remember',
                    $rememberToken,
                    [...$cookie_set_options, 'expires' => $expiry_one_year]
                );

                // iii. CRITICAL FIX: Make the PHPSESSID cookie long-lived too.
                // This overwrites the non-persistent session cookie set at the top.
                setcookie(
                    session_name(),
                    session_id(),
                    [...$cookie_set_options, 'expires' => $expiry_one_year]
                );
                
            } else {
                // B) USER DID NOT CHECK 'REMEMBER ME' (Needs to remove persistence)

                // i. Delete persistent token from DB
                $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")
                    ->execute([$user_id]);

                // ii. Delete 'quill_remember' cookie (past expiry)
                setcookie(
                    'quill_remember',
                    '',
                    [...$cookie_set_options, 'expires' => time() - 3600]
                );

                // iii. CRITICAL FIX: Delete any persistent PHPSESSID and generate a fresh, non-persistent one.
                if (isset($_COOKIE[session_name()])) {
                    // Delete the old, potentially sticky PHPSESSID cookie in the browser
                    setcookie(
                        session_name(),
                        '',
                        [...$cookie_set_options, 'expires' => time() - 3600]
                    );
                }
                // Regenerate the session ID to issue a new, guaranteed non-persistent PHPSESSID (due to the top-level config)
                session_regenerate_id(true);
            }
            
            dbg_write(['event'=>'normal-login-success','user_id'=>$user_id,'token'=>$token,'session_id'=>session_id()]);

            // redirect to app with token
            header("Location: app.php?token=" . urlencode($token));
            exit;
        } else {
            $login_error = "Invalid credentials.";
            dbg_write(['event'=>'normal-login-failed','identifier'=>$identifier]);
        }
    } catch (Throwable $e) {
        $login_error = "Database error.";
        dbg_write(['event'=>'normal-login-exception','message'=>$e->getMessage()]);
    }
}

// ----------------- MICROSOFT OAUTH STATE CREATION -----------------
// Create a fresh state per page load and store in session.
// This is what the callback must match.
$state = bin2hex(random_bytes(16));
$_SESSION['ms_oauth_state'] = $state;

// log the fact the state was created
dbg_write([
    'event' => 'ms_state_created',
    'state_saved' => $state,
    'session_id' => session_id(),
    'cookie_sid' => $_COOKIE[session_name()] ?? null
]);

$msAuthUrl = "https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?" .
    http_build_query([
        'client_id'       => $ms_client_id,
        'response_type' => 'code',
        'redirect_uri'  => $ms_redirect_uri,
        'response_mode' => 'query',
        'scope'         => 'openid email profile User.Read',
        'state'         => $state
    ]);

// If debug param present show a small diagnostics box (safe)
if ($DEBUG) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "DEBUG MODE\n";
    echo "session_id: " . session_id() . "\n";
    echo "_SESSION keys: " . implode(", ", array_keys($_SESSION)) . "\n";
    echo "_GET: " . json_encode($_GET) . "\n";
    echo "_COOKIE: " . json_encode($_COOKIE) . "\n";
    echo "msAuthUrl: " . $msAuthUrl . "\n";
    echo "ms_state_saved: " . ($_SESSION['ms_oauth_state'] ?? 'NONE') . "\n";
    echo "\n";
    echo "Tail of ms_debug.log:\n\n";
    echo `tail -n 40 ` . __DIR__ . '/ms_debug.log';
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo SITE_DIR; ?>">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <title><?php echo __('login_title'); ?> - QuillTalk</title>
    <style>

        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            font-family: 'Raleway', sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 0;
        }

        header {
            background: linear-gradient(135deg, #2d58f5 0%, rgba(4, 12, 38, 0.84) 46%, rgba(45, 88, 245, 0.22) 100%);
            padding: 20px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            top: 0;
            z-index: 100;
        }

        * {
            box-sizing: border-box;
        }

        /* style the container */
        .container {
            position: relative;
            border-radius: 5px;
            background-color: #ffffff;
            padding: 20px 30px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
            margin: 20px auto 40px;
            /* space below header */
            text-align: center;
        }

        /* RTL: Adjust text-align in the container */
        html[dir="rtl"] .container {
            text-align: right;
        }

        /* style inputs and link buttons */
        input,
        .btn {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin: 8px 0;
            opacity: 0.95;
            display: block;
            font-size: 16px;
            line-height: 20px;
            text-decoration: none;
            text-align: center;
            transition: opacity 0.3s ease;
        }

        input:focus {
            border-color: #2d58f5;
            /* Changed focus color for consistency */
            outline: none;
        }

        input:hover,
        .btn:hover {
            opacity: 1;
        }

        /* RTL: Input placeholders and text should be right-aligned */
        html[dir="rtl"] input {
            text-align: right;
        }

        /* add appropriate colors to fb, twitter and google buttons */
        .microsoft {
            background-color: #3B5998;
            color: white;
        }

        .passkeys {
            background-color: #55ACEE;
            color: white;
        }

        .google {
            background-color: #dd4b39;
            color: white;
        }

        /* RTL: Flip the icon alignment on social buttons */
        html[dir="rtl"] .social-buttons a i {
            margin-right: 0;
            margin-left: 10px;
        }

        /* style the submit button */
        input[type=submit] {
            background-color: #2d58f5;
            color: white;
            cursor: pointer;
            border: none;
            margin-top: 20px;
        }

        input[type=submit]:hover {
            background-color: #2e53d9;
        }

        .hide-md-lg {
            display: block;
            text-align: center;
            margin: 20px 0 10px 0;
            font-size: 15px;
            color: #555;
        }

        /* RTL: Adjust alignment for manual sign-in prompt */
        html[dir="rtl"] .hide-md-lg {
            text-align: right;
        }

        /* New styles for the "Sign up" and "Forgot password" buttons */
        .manual-login .btn {
            background-color: #777;
            color: white;
            border: none;
            margin-top: 5px;
            font-size: 15px;
            padding: 10px;
        }

        .manual-login .btn:hover {
            background-color: #555;
        }

        /* Responsive adjustments */
        @media screen and (max-width: 450px) {
            .container {
                max-width: 95%;
                padding: 15px 20px;
            }

            input,
            .btn {
                padding: 10px;
                font-size: 15px;
            }
        }

        nav a {
            color: white;
            text-decoration: none;
            font-size: 18px;
            font-weight: bold;
            margin-left: 25px;
            transition: opacity 0.3s ease;
        }

        /* RTL: Flip nav links to the right */
        html[dir="rtl"] nav a {
            margin-left: 0;
            margin-right: 30px;
        }

        /* --- Language Dropdown Styles --- */
        .nav-container {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        nav a {
            color: white;
            text-decoration: none;
            margin-left: 30px;
            font-size: 18px;
            font-weight: bold;
        }

        /* RTL adjustments for nav links */
        html[dir="rtl"] nav a {
            margin-left: 0;
            margin-right: 30px;
        }

        html[dir="rtl"] .nav-container {
            flex-direction: row-reverse;
            gap: 30px;
        }

        .language-dropdown {
            position: relative;
        }

        /* RTL adjustment for dropdown position */
        html[dir="rtl"] .language-dropdown {
            margin-left: 0;
            margin-right: 30px;
        }
        .lang-button {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }

        .lang-button:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .dropdown-content {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background-color: #f9f9f9;
            min-width: 120px;
            box-shadow: 0px 8px 16px rgba(0, 0, 0, 0.2);
            border-radius: 5px;
            overflow: hidden;
            z-index: 1;
        }

        /* RTL adjustment for dropdown alignment */
        html[dir="rtl"] .dropdown-content {
            right: auto;
            left: 0;
            /* Align dropdown to the left of the button */
        }

        .dropdown-content a {
            color: #333;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            margin-left: 0;
            text-align: left;
            font-weight: normal;
        }

        /* RTL adjustment for link text alignment */
        html[dir="rtl"] .dropdown-content a {
            text-align: right;
        }

        .dropdown-content a:hover {
            background-color: #ddd;
        }

        .language-dropdown:hover .dropdown-content {
            display: block;
        }

        /* --- End Language Dropdown Styles --- */

        /* --- Custom Message Box Styles --- */
        #messageBox {
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease-in-out;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            font-size: 14px;
            text-align: center;
        }

        #messageBox.show {
            opacity: 1;
            transform: translateY(0);
        }

        #messageBox.error {
            background-color: #fcebeb; /* Light Red */
            color: #a94442; /* Darker Red */
            border: 1px solid #ebcccc;
        }

        #messageBox.success {
            background-color: #dff0d8; /* Light Green */
            color: #3c763d; /* Darker Green */
            border: 1px solid #d6e9c6;
        }
        /* --- End Message Box Styles --- */
        
        .login-header-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        #loginTitle {
            margin: 0 0 10px 0; /* small spacing under heading */
            transition: margin-bottom 0.25s ease;
        }

        #messageBox {
            opacity: 0;
            max-height: 0;
            overflow: hidden;
            transition: opacity 0.25s ease, max-height 0.25s ease, margin-bottom 0.25s ease;
            margin-bottom: 0;
        }

        #messageBox.show {
            opacity: 1;
            max-height: 200px;
            margin-bottom: 15px; /* expands spacing only when message exists */
        }
        
        /* Toggle Switch Styles */
        .toggle-switch {
            position: relative;
            width: 50px;
            height: 24px;
            display: inline-block;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
            margin: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.3s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.3s;
            border-radius: 50%;
        }

        .toggle-switch input:checked + .toggle-slider {
            background-color: #2d58f5;
        }

        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }

        .switch-container {
            user-select: none;
        }
    </style>
    </head>
<body>
    <header>
        <a href="index.php">
            <img src="images/logo.png" alt="Quill logo." style="height: 50px; cursor: pointer;">
        </a>
        <div class="nav-container">
            <nav>
                <a href="signup.php"><?php echo __('sign_up'); ?></a>
                <a href="login.php"><?php echo __('login'); ?></a>
            </nav>
            <div class="language-dropdown">
                <button class="lang-button">
                    <i class="fas fa-globe"></i>
                    <span id="current-lang-text"><?php echo ($lang == 'ar' ? 'العربية' : 'English'); ?></span>
                </button>
                <div class="dropdown-content">
                    <a href="#" data-lang="en">English</a>
                    <a href="#" data-lang="ar">العربية</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <form id="loginForm" method="POST" action="https://quilltalk.org/login.php">
            <div class="row">
                <div class="login-header-wrapper">
                    <h2 id="loginTitle" class="dynamic-gap" style="text-align:center; color: #333;">
                        <?php echo __('login_title'); ?>
                    </h2>
                    <div id="messageBox"></div>
                </div>
  
                <?php if (!empty($login_error)) echo "<p style='color:red; font-size: 14px;'>$login_error</p>"; ?>
              
                <div class="social-buttons">
                    <a href="google_login.php" class="google btn">
                        <i class="fa-brands fa-google"></i> <?php echo __('login_with_google'); ?>
                    </a>
                    
                    <a href="#" id="passkeyLoginButton" class="passkeys btn">
                        <i class="fa-solid fa-key"></i> <?php echo __('login_with_passkeys'); ?>
                    </a>

                    <a href="<?php echo htmlspecialchars($msAuthUrl); ?>" class="microsoft btn">
                        <i class="fa-brands fa-microsoft"></i> <?php echo __('login_with_microsoft'); ?>
                    </a>
                </div>

                <div class="manual-login">
                    <div class="hide-md-lg">
                        <p><?php echo __('or_sign_in_manually'); ?></p>
                    </div>

                    <input type="text" name="username" id="usernameInput" placeholder="<?php echo __('email'); ?>" required>
                    <input type="password" name="password" placeholder="<?php echo __('password'); ?>" required>

                    <div style="display: flex; align-items: center; justify-content: space-between; margin: 10px 0;">
                        <label class="switch-container" style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <span id="loginModeLabel" style="font-size: 14px; color: #333;">Login with Email</span>
                            <div class="toggle-switch">
                                <input type="checkbox" id="loginModeToggle" checked>
                                <span class="toggle-slider"></span>
                            </div>
                        </label>
                    </div>

                    <input type="checkbox" name="remember_me" id="remember_me">
                    <label for="remember_me" style="font-size:14px; color:#333;"><?php echo __('remember_me'); ?></label>

                    <input type="submit" value="<?php echo __('login_title'); ?>">

                    <a href="forgot_password.php" class="btn"><?php echo __('forgot_password'); ?></a>

                    <p style="margin-top: 15px; font-size: 14px; color: #666;">
                        <?php echo __('dont_have_account'); ?>
                        <a href="signup.php" style="color: #2d58f5; text-decoration: none;"><?php echo __('sign_up'); ?></a>
                    </p>
                </div>
            </div>
        </form>
    </div>

    <script>
/* =============== LOGIN MODE TOGGLE ================= */
document.addEventListener("DOMContentLoaded", () => {
    const toggle = document.getElementById("loginModeToggle");
    const label = document.getElementById("loginModeLabel");
    const input = document.getElementById("usernameInput");
    
    if (toggle && label && input) {
        // Set initial state (email mode by default)
        updateLoginMode();
        
        toggle.addEventListener("change", updateLoginMode);
        
        function updateLoginMode() {
            if (toggle.checked) {
                // Email mode
                label.textContent = "Login with Email";
                input.placeholder = "<?php echo __('email'); ?>";
                input.type = "email";
                input.name = "email";
            } else {
                // Username mode
                label.textContent = "Login with Username";
                input.placeholder = "<?php echo __('username'); ?>";
                input.type = "text";
                input.name = "username";
            }
        }
    }
});

/* =============== BASE64 HELPERS ================= */
const passkeyBtn = document.getElementById("passkeyLoginButton");
let originalPasskeyHTML = passkeyBtn.innerHTML;

function b64ToUint8Array(b64url) {
    if (!b64url || typeof b64url !== "string") return new Uint8Array();

    let padding = "=".repeat((4 - b64url.length % 4) % 4);
    let base64 = (b64url + padding).replace(/-/g, "+").replace(/_/g, "/");
    let raw = window.atob(base64);

    let arr = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        arr[i] = raw.charCodeAt(i);
    }
    return arr;
}

function uint8ToBase64(arr) {
    let str = "";
    for (let i = 0; i < arr.length; i++) str += String.fromCharCode(arr[i]);
    return window.btoa(str)
        .replace(/\+/g, "-")
        .replace(/\//g, "_")
        .replace(/=+$/, "");
}

/* ================ UI HELPERS ==================== */

function showMessage(msg, isError = true) {
    const box = document.getElementById("messageBox");

    if (msg && msg.length > 0) {
        box.textContent = msg;
        box.className = "show " + (isError ? "error" : "success");
    } else {
        box.textContent = "";
        box.className = "";
    }
}

/* ============== PASSKEY LOGIN CORE ============== */

async function startPasskeyLogin(e) {
    e.preventDefault();

    const btn = document.getElementById("passkeyLoginButton");
    const usernameInput = document.getElementById("usernameInput");
    const rememberMe = document.querySelector('input[name="remember_me"]');

    const identifier = usernameInput ? usernameInput.value.trim() : "";

    /** disable button */
    btn.disabled = true;
    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Requesting challenge...`;

    try {
        /* 1️⃣ GET OPTIONS FROM SERVER */
        const resp = await fetch("start-login.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "include",
            body: JSON.stringify({ identifier })
        });

        const data = await resp.json().catch(() => ({}));

        if (!data || !data.success) {
            throw new Error(data.error || "Invalid server response.");
        }

        const challengeToken = data.challengeToken;
        const pub = data.publicKey || {};

        if (!pub.challenge) {
            throw new Error("Server failed to provide challenge.");
        }

        /* normalize */
        pub.challenge = b64ToUint8Array(pub.challenge);

        if (!Array.isArray(pub.allowCredentials)) {
            pub.allowCredentials = [];
        }

        pub.allowCredentials = pub.allowCredentials.map(item => ({
            type: item.type,
            id: b64ToUint8Array(item.id)
        }));

        /* 2️⃣ BROWSER GETS ASSERTION */
        btn.textContent = "Waiting for passkey...";

        const assertion = await navigator.credentials.get({ publicKey: pub });

        /* 3️⃣ SEND TO SERVER */
        btn.textContent = "Verifying...";

        const verifyResp = await fetch("finish-login.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "include",
            body: JSON.stringify({
                challengeToken,
                remember_me: rememberMe && rememberMe.checked,
                id: assertion.id,
                rawId: uint8ToBase64(new Uint8Array(assertion.rawId)),
                response: {
                    clientDataJSON: uint8ToBase64(new Uint8Array(assertion.response.clientDataJSON)),
                    authenticatorData: uint8ToBase64(new Uint8Array(assertion.response.authenticatorData)),
                    signature: uint8ToBase64(new Uint8Array(assertion.response.signature)),
                    userHandle: assertion.response.userHandle
                        ? uint8ToBase64(new Uint8Array(assertion.response.userHandle))
                        : null
                }
            })
        });

        const verifyData = await verifyResp.json().catch(() => ({}));

        if (!verifyData || !verifyData.success) {
            throw new Error(verifyData.error || "Verification failed.");
        }

        showMessage("Login successful! Redirecting...", false);

        /* 4️⃣ redirect */
        window.location.href = "app.php?token=" +
            encodeURIComponent(verifyData.token);

    } catch (err) {
        console.error("Passkey Login Error:", err);
        showMessage("Login failed: " + err.message, true);
    } finally {
        btn.disabled = false;
		btn.innerHTML = originalPasskeyHTML;
    }
}

/* =============== INIT =========================== */

document.addEventListener("DOMContentLoaded", () => {
    const btn = document.getElementById("passkeyLoginButton");
    if (!btn) return;

    if (!window.PublicKeyCredential) {
        btn.style.display = "none";
        return;
    }

    btn.addEventListener("click", startPasskeyLogin);
});
</script>
</body>

</html>
