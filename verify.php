<?php
// --- 1. START: PHP LANGUAGE SETUP BLOCK (MUST BE FIRST) ---
// This block defines $lang, SITE_DIR, and makes the __() function available.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Include the consolidated language file
require_once __DIR__ . '/lang.php'; 
$lang = isset($_COOKIE['quill_language']) ? $_COOKIE['quill_language'] : 'en';
define('SITE_DIR', ($lang == 'ar' ? 'rtl' : 'ltr'));
// --- END: PHP LANGUAGE SETUP BLOCK ---

header("Access-Control-Allow-Origin: *");
require __DIR__ . '/includes/db.php';

$success = false;
$error = '';
$continueToken = '';
$verifiedProfilePic = 'images/default-profile.png';
$verifiedDisplayName = '';
$verifiedUsername = '';
$currentEmail = trim((string)($_POST['email'] ?? $_GET['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));
    $currentEmail = $email;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND verification_token = ?");
    $stmt->execute([$email, $code]);
    $user = $stmt->fetch();

    if ($user) {
        $update = $pdo->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE email = ?");
        $update->execute([$email]);
        $continueToken = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO sessions (user_id, token) VALUES (?, ?)")
            ->execute([(int)$user['id'], $continueToken]);
        $_SESSION['user_id'] = (int)$user['id'];
        $verifiedDisplayName = (string)($user['display_name'] ?? $user['username'] ?? '');
        $verifiedUsername = (string)($user['username'] ?? '');
        $verifiedProfilePic = !empty($user['profile_pic']) ? (string)$user['profile_pic'] : 'images/default-profile.png';
        $success = true;
    } else {
        // Use translated error message
        $error = __('error_invalid_code');
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo SITE_DIR; ?>">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Use translated title -->
    <title><?php echo __('verify_title'); ?> - QuillTalk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Raleway', sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 999;
            background: linear-gradient(135deg, #2d58f5 0%, rgba(4, 12, 38, 0.84) 46%, rgba(45, 88, 245, 0.22) 100%);
            padding: 25px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .container {
            position: relative;
            border-radius: 5px;
            background-color: #ffffff;
            padding: 20px 30px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            margin: 150px auto 40px; /* space below header */
            text-align: center;
        }
        
        /* RTL: Adjust container text alignment */
        html[dir="rtl"] .container {
            text-align: right;
        }
        
        input {
            padding: 12px;
            width: 93%;
            margin: 10px 0;
            font-size: 16px;
            border-radius: 8px;
            opacity: 0.95;
            border: 1px solid #ccc;
        }
        
        /* RTL: Input alignment */
        html[dir="rtl"] input {
            text-align: right;
        }
        
        button {
            padding: 12px;
            background: #2d58f5;
            color: white;
            border: none;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            border-radius: 8px;
            opacity: 0.95;
            transition: opacity 0.3s ease;
        }

        input:hover,
        button:hover {
            opacity: 1;
        }

        input:focus {
            border-color: #2d58f5;
            outline: none;
        }

        .message {
            margin-top: 15px;
            font-size: 15px;
        }
        .success {
            color: green;
        }
        .error {
            color: red;
        }

        nav a {
            color: white;
            text-decoration: none;
            margin-left: 30px;
            font-size: 18px;
            font-weight: bold;
        }
        /* RTL: Flip nav links to the right */
        html[dir="rtl"] nav a {
            margin-left: 0;
            margin-right: 30px;
        }

        .container.success-state {
            max-width: 560px;
            text-align: left;
        }

        .verify-success-card {
            display: flex;
            flex-direction: column;
            gap: 18px;
            padding: 10px 2px 4px;
        }

        .verify-success-main {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 20px;
            border-radius: 18px;
            border: 1px solid #d8e1ff;
            background: #f7f9ff;
        }

        .verify-success-avatar {
            width: 92px;
            height: 92px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            background: #dbe4ff;
        }

        .verify-success-copy {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            min-width: 0;
        }

        .verify-success-title {
            margin: 0;
            color: #111827;
            font-size: 1.16rem;
            font-weight: 600;
            line-height: 1.5;
        }

        .verify-success-detail {
            margin: 0;
            color: #334155;
            font-size: 0.98rem;
            line-height: 1.5;
            word-break: break-word;
        }

        .verify-success-actions {
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

        @media screen and (max-width: 450px) {
            .verify-success-main {
                gap: 14px;
                padding: 16px;
            }

            .verify-success-avatar {
                width: 74px;
                height: 74px;
            }

            .verify-success-copy {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <a href="index.php">
            <img src="images/logo.png" alt="Quill logo." style="height: 50px; cursor: pointer;">
        </a>
        <nav>
            <!-- Use translation for navigation links -->
            <a href="signup.php"><?php echo __('sign_up'); ?></a>
            <a href="login.php"><?php echo __('login'); ?></a>
        </nav>
    </header>
    <div class="container<?= $success ? ' success-state' : '' ?>">
        <!-- Use translated title -->
        <h2><?php echo __('verify_title'); ?></h2>
        <?php if ($success): ?>
            <div class="verify-success-card">
                <div class="verify-success-main">
                    <img
                        src="<?= htmlspecialchars($verifiedProfilePic, ENT_QUOTES, 'UTF-8') ?>"
                        alt="<?= htmlspecialchars($verifiedDisplayName, ENT_QUOTES, 'UTF-8') ?>"
                        class="verify-success-avatar"
                    >
                    <div class="verify-success-copy">
                        <p class="verify-success-title">Success! You have created your QuillTalk account.</p>
                        <p class="verify-success-detail">Display Name: <?= htmlspecialchars($verifiedDisplayName, ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="verify-success-detail">Username: <?= htmlspecialchars($verifiedUsername, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <div class="verify-success-actions">
                    <a class="continue-btn" href="app.php?token=<?= urlencode($continueToken) ?>">Continue</a>
                </div>
            </div>
            <?php if (false): ?>
            <p class="message success">
                ✅ <?php echo __('success_verified'); ?> 
                <a href="login.php"><?php echo __('login'); ?></a>.
            </p>
            <?php endif; ?>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="email" value="<?= htmlspecialchars($currentEmail, ENT_QUOTES, 'UTF-8') ?>">
                <!-- Use translated placeholder -->
                <input type="text" name="code" placeholder="<?php echo __('enter_code_placeholder'); ?>" required>
                <!-- Use translated button text -->
                <button type="submit"><?php echo __('verify_button'); ?></button>
            </form>
            <?php if ($error): ?>
                <p class="message error"><?= $error ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
