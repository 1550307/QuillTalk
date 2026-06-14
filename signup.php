<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/quilltalk-backend/vendor/autoload.php';
require_once __DIR__ . '/lang.php';
require __DIR__ . '/includes/db.php'; // Make sure $pdo is ready

$lang = isset($_COOKIE['quill_language']) ? $_COOKIE['quill_language'] : 'en';
define('SITE_DIR', ($lang == 'ar' ? 'rtl' : 'ltr'));

header("Access-Control-Allow-Origin: *");

use \Mailjet\Resources;

$errorMessage = '';
$signupCompleted = false;
$successEmail = '';
$successDisplayName = '';
$successProfilePic = 'images/default-profile.png';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $displayName       = trim((string)($_POST['display_name'] ?? ''));
    $email             = trim((string)($_POST['email'] ?? ''));
    $passwordRaw       = (string)($_POST['password'] ?? '');
    $verification_code = rand(100000, 999999);
    $password          = password_hash($passwordRaw, PASSWORD_DEFAULT);
    $generatedUsername = generate_unique_username($pdo, $displayName);
    $safeDisplayNameForEmail = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');

    $profilePicPath = null;

    /* -----------------------------
       PROFILE PIC UPLOAD (NO RESIZE)
    ----------------------------- */
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] !== UPLOAD_ERR_NO_FILE) {

        $uploadDir = __DIR__ . '/uploads/profiles/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $fileTmpPath = $_FILES['profile_pic']['tmp_name'];
        $fileName    = basename($_FILES['profile_pic']['name']);
        $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($fileExt, $allowedExts)) die("❌ Invalid file type: $fileExt");

        $newFileName = uniqid('profile_', true) . '.' . $fileExt;
        $destPath    = $uploadDir . $newFileName;

        if (!move_uploaded_file($fileTmpPath, $destPath)) {
            die("❌ Failed to save uploaded file.");
        }

        $profilePicPath = 'uploads/profiles/' . $newFileName;
    }

    /* -----------------------------
       INSERT USER
    ----------------------------- */
    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (
                username,
                display_name,
                email,
                password_hash,
                verification_token,
                profile_pic,
                bio,
                created_at,
                is_verified,
                is_passkey_user
            ) VALUES (?, ?, ?, ?, ?, ?, '', NOW(), 0, 0)
        ");

        $stmt->execute([
            $generatedUsername,
            $displayName,
            $email,
            $password,
            $verification_code,
            $profilePicPath
        ]);

        /* -----------------------------
           SEND VERIFICATION EMAIL
        ----------------------------- */
        $mj = new \Mailjet\Client(
            '7e8fccae9161da8d7c240c379532756d',
            '7b0d2ccc3317330e1aa4e11563bed8d3',
            true,
            ['version' => 'v3.1']
        );

        $body = [
            'Messages' => [
                [
                    'From' => ['Email' => "noreply@quilltalk.org", 'Name' => "QuillTalk"],
                    'To'   => [['Email' => $email, 'Name' => $displayName]],
                    'Subject' => "Your QuillTalk Verification Code",
                    'HTMLPart' => "<p>Hey {$safeDisplayNameForEmail},</p><p>Your verification code is:</p><h2>{$verification_code}</h2>"
                ]
            ]
        ];

        $response = $mj->post(Resources::$Email, ['body' => $body]);
        if (!$response->success()) throw new Exception('Mailjet Error: ' . json_encode($response->getData()));

        header("Location: verify.php?email=" . urlencode($email));
        exit;

    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            if (str_contains($e->getMessage(), 'users.username')) {
                $errorMessage = "❌ Username already taken.";
            } elseif (str_contains($e->getMessage(), 'users.email')) {
                $errorMessage = "❌ Email already exists.";
            } else {
                $errorMessage = "❌ Duplicate entry.";
            }
        } else {
            $errorMessage = "❌ Database error: " . htmlspecialchars($e->getMessage());
        }
        
    } catch (Exception $e) {
        $errorMessage = "Email sending failed: " . htmlspecialchars($e->getMessage());
        die("❌ Email sending failed: " . htmlspecialchars($e->getMessage()));
    }
}

/* --- OAuth Microsoft --- */
$state = bin2hex(random_bytes(16));
$_SESSION['ms_oauth_state'] = $state;
$ms_client_id       = "11cd13ae-d98d-402a-b5b2-950e21dabd15";
$ms_client_secret   = "qSI8Q~RSdIVnxyrBzPNT6HBEJBoP6m-wgGgsnczU";
$ms_redirect_uri    = "https://quilltalk.org/microsoft-callback.php";
$msAuthUrl = "https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?" .
    http_build_query([
        'client_id' => $ms_client_id,
        'response_type' => 'code',
        'redirect_uri' => $ms_redirect_uri,
        'response_mode' => 'query',
        'scope' => 'openid email profile User.Read',
        'state' => $state
    ]);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= SITE_DIR ?>">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= __('signup_title'); ?> - QuillTalk</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="icon" type="image/x-icon" href="images/favicon.ico">
<link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600&display=swap" rel="stylesheet">
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
            padding: 25px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        * {
            box-sizing: border-box;
        }

        /* Container */
        .container {
            position: relative;
            border-radius: 5px;
            background-color: #ffffff;
            padding: 20px 30px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            margin: 20px auto 40px;
            text-align: center;
        }

        .container.success-state {
            max-width: 560px;
            text-align: left;
        }

        html[dir="rtl"] .container {
            text-align: right;
        }

        /* Inputs and buttons */
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
            outline: none;
        }

        input:hover,
        .btn:hover {
            opacity: 1;
        }

        html[dir="rtl"] input {
            text-align: right;
        }

        /* Social buttons */
        .google {
            color: white;
            background-color: #dd4b39;
        }

        .passkeys {
            background-color: #55ACEE;
            color: white;
        }

        .microsoft {
            background-color: #3B5998;
            color: white;
        }

        /* Submit button */
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

        /* Error message */
        .error-message {
            color: white;
            background-color: #d9534f;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .signup-success-card {
            display: flex;
            flex-direction: column;
            gap: 18px;
            padding: 10px 2px 4px;
        }

        .signup-success-main {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 20px;
            border-radius: 18px;
            border: 1px solid #d8e1ff;
            background: #f7f9ff;
        }

        .signup-success-avatar {
            width: 92px;
            height: 92px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            background: #dbe4ff;
        }

        .signup-success-copy {
            margin: 0;
            color: #111827;
            font-size: 1.16rem;
            font-weight: 600;
            line-height: 1.5;
        }

        .signup-success-actions {
            display: flex;
            justify-content: flex-end;
        }

        .continue-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 124px;
            padding: 12px 22px;
            border-radius: 12px;
            border: none;
            background: #2d58f5;
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.2s ease, transform 0.2s ease;
        }

        .continue-btn:hover {
            background: #2448d8;
            transform: translateY(-1px);
        }

        /* Responsive */
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

            .signup-success-main {
                gap: 14px;
                padding: 16px;
            }

            .signup-success-avatar {
                width: 74px;
                height: 74px;
            }

            .signup-success-copy {
                font-size: 1rem;
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
            box-shadow: 0px 8px 16px rgba(0,0,0,0.2);
            border-radius: 5px;
            overflow: hidden;
            z-index: 1;
        }

        /* RTL adjustment for dropdown alignment */
        html[dir="rtl"] .dropdown-content {
            right: auto;
            left: 0; /* Align dropdown to the left of the button */
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
        
        .modal {
          display: none;
          position: fixed;
          z-index: 9999;
          left: 0; top: 0;
          width: 100%; height: 100%;
          background: rgba(0,0,0,0.6);
          justify-content: center;
          align-items: center;
        }

        /* Modal content */
        .modal-content {
          background: #fff;
          padding: 20px;
          border-radius: 10px;
          position: relative;
          max-width: 600px;
          width: 90%;
          display: flex;
          flex-direction: column;
          align-items: center;
        }

        /* Crop container & circle overlay */
        .crop-container {
          position: relative;
          width: 300px;
          height: 300px;
          margin-bottom: 10px;
        }
        #cropCanvas {
          width: 100%; height: 100%;
          border-radius: 50%;
          cursor: grab;
        }
        .crop-circle {
          position: absolute;
          top: 0; left: 0;
          width: 100%; height: 100%;
          border-radius: 50%;
          box-shadow: 0 0 0 9999px rgba(0,0,0,0.5);
          pointer-events: none;
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

<div class="container<?= $signupCompleted ? ' success-state' : '' ?>">
    <h2 style="text-align:center; color: #333; margin-bottom: 25px;">
        <?php echo __('signup_title'); ?>
    </h2>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><?= $errorMessage ?></div>
    <?php endif; ?>

    <?php if ($signupCompleted): ?>
        <div class="signup-success-card">
            <div class="signup-success-main">
                <img
                    src="<?= htmlspecialchars($successProfilePic, ENT_QUOTES, 'UTF-8') ?>"
                    alt="<?= htmlspecialchars($successDisplayName, ENT_QUOTES, 'UTF-8') ?>"
                    class="signup-success-avatar"
                >
                <p class="signup-success-copy">Success! You have created your QuillTalk account.</p>
            </div>
            <div class="signup-success-actions">
                <a class="continue-btn" href="verify.php?email=<?= urlencode($successEmail) ?>">Continue</a>
            </div>
        </div>
    <?php else: ?>
        <div class="social-buttons">
            <a href="google_login.php" class="google btn">
                <i class="fa-brands fa-google"></i> <?php echo __('signup_with_google'); ?>
            </a>
            <a href="javascript:startPasskeyRegistration()" class="passkeys btn">
                <i class="fa-solid fa-key"></i> <?php echo __('signup_with_passkeys'); ?>
            </a>
            <a href="<?php echo htmlspecialchars($msAuthUrl); ?>" class="microsoft btn">
                <i class="fa-brands fa-microsoft"></i> <?php echo __('signup_with_microsoft'); ?>
            </a>
        </div>

        <div class="manual-login">
            <div class="hide-md-lg">
            	<p>Or sign up manually:</p>
            </div>
            
            <form method="POST" action="signup.php" enctype="multipart/form-data">
                <input type="text" name="display_name" placeholder="Display Name" required>
                <input type="email" name="email" placeholder="<?php echo __('email_address'); ?>" required>
                <input type="password" name="password" placeholder="<?php echo __('password'); ?>" required>
    			<div class="hide-md-lg">
            		<p>Profile Picture (Optional)</p>
            	</div>
                <input type="file" name="profile_pic" accept="image/*">
                
                <label for="privacy">
                    <?php echo __('agree'); ?>
                    <a href="privacy.html"><?php echo __('privacy'); ?>
</a>
    				<?php echo __('and'); ?>
                    <a href="terms.html"><?php echo __('terms'); ?></a>.
                </label>
                <input type="checkbox" id="privacy" name="privacy" required>

                <input type="submit" value="Next">
            </form>

            <p style="margin-top: 15px; font-size: 14px; color: #666;">
                <?php echo __('already_account'); ?>
                <a href="login.php" style="color:#2d58f5;"><?php echo __('login'); ?></a>
            </p>
        </div>
    <?php endif; ?>
</div>
<script>
    // Force HTTPS redirect
    if (window.location.protocol === "http:") {
        window.location.href = window.location.href.replace("http", "https");
    }

    // --- LANGUAGE HANDLER JAVASCRIPT ---
    document.querySelectorAll('.dropdown-content a').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const lang = this.getAttribute('data-lang');

            // 1. Set the cookie to expire in 365 days
            document.cookie = `quill_language=${lang}; expires=${new Date(new Date().getTime() + 365 * 24 * 60 * 60 * 1000).toUTCString()}; path=/;`;

            // 2. Reload the page to apply changes (PHP will pick up the cookie)
            window.location.reload();
        });
    });
</script>
<script>
// --- PASSKEY REGISTRATION JAVASCRIPT (FINAL, TOKEN-BASED FIX) ---

function b64ToUint8Array(b64) {
    return Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
}

function uint8ToBase64(u8) {
    return btoa(String.fromCharCode(...u8)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

/**
 * CRITICAL FIX: Custom function to handle JSON parsing of a corrupted string (fixes initial errors).
 */
async function getCleanJson(response) {
    const rawText = await response.text();
    const jsonStartIndex = rawText.indexOf('{');

    if (jsonStartIndex === -1) {
        console.error("Raw Server Response (No JSON found):", rawText);
        throw new Error('Server returned unrecoverable content (No JSON payload found).');
    }

    try {
        const jsonString = rawText.substring(jsonStartIndex);
        return JSON.parse(jsonString);
    } catch (e) {
        console.error("JSON Recovery Failed on string:", rawText.substring(jsonStartIndex, jsonStartIndex + 100) + '...');
        throw new Error('Failed to recover clean JSON from corrupted server response.');
    }
}


// call to start registration
async function startPasskeyRegistration() {
    const displayName = document.querySelector('input[name="display_name"]').value;
    const email = document.querySelector('input[name="email"]').value;
    const password = document.querySelector('input[name="password"]').value;

    try {
        // POST signup info to start-registration.php
        const res = await fetch('start-registration.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ display_name: displayName, email, password })
        });

        const options = await res.json();
        if (options.error) {
            alert('Passkey registration failed: ' + options.error);
            return;
        }

        console.log('WebAuthn options received:', options);

        // Convert binary fields
        options.challenge = b64ToUint8Array(options.challenge);
        options.user.id = b64ToUint8Array(options.user.id);

        if (options.excludeCredentials) {
            options.excludeCredentials = options.excludeCredentials.map(c => ({
                ...c,
                id: b64ToUint8Array(c.id)
            }));
        }

        const cred = await navigator.credentials.create({ publicKey: options });

        const rawId = uint8ToBase64(new Uint8Array(cred.rawId));
        const clientDataJSON = uint8ToBase64(new Uint8Array(cred.response.clientDataJSON));
        const attestationObject = uint8ToBase64(new Uint8Array(cred.response.attestationObject));

        const formData = new URLSearchParams();
        formData.append('passkey_data', JSON.stringify({
            id: cred.id,
            rawId,
            response: { attestationObject, clientDataJSON }
        }));

        const verifyRes = await fetch('finish-registration.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        });

        const verifyData = await verifyRes.json();

        if (verifyData.success) {
            alert('Passkey registered successfully!');
            window.location.href = 'app.php?token=' + encodeURIComponent(verifyData.token);
        } else {
            alert('Registration failed: ' + verifyData.message);
        }

    } catch (err) {
        console.error(err);
        alert('Passkey registration failed: ' + err.message);
    }
}
</script>
</body>
</html>
