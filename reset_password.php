<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/quilltalk-backend/vendor/autoload.php';
require_once __DIR__ . '/lang.php';
require __DIR__ . '/includes/db.php';

$lang = isset($_COOKIE['quill_language']) ? $_COOKIE['quill_language'] : 'en';
define('SITE_DIR', ($lang == 'ar' ? 'rtl' : 'ltr'));

$errorMessage = '';
$successMessage = '';
$showForm = true;

// 1️⃣ Check for token
if (!isset($_GET['token']) || strlen($_GET['token']) < 10) {
    $errorMessage = "❌ Invalid or missing reset token.";
    $showForm = false;
} else {
    $token = $_GET['token'];

    // 2️⃣ Validate token in database
    $stmt = $pdo->prepare("SELECT id, email, reset_expires FROM users WHERE reset_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $errorMessage = "❌ This reset link is invalid.";
        $showForm = false;
    } elseif (strtotime($user['reset_expires']) < time()) {
        $errorMessage = "❌ This reset link has expired. Request a new one.";
        $showForm = false;
    }
}

// 3️⃣ Handle password reset submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password']) && $showForm) {

    $newPassword = trim($_POST['new_password']);
    $confirmPassword = trim($_POST['confirm_password']);

    if ($newPassword !== $confirmPassword) {
        $errorMessage = "❌ Passwords do not match.";
    } elseif (strlen($newPassword) < 6) {
        $errorMessage = "❌ Password must be at least 6 characters.";
    } else {
        // Hash new password
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update database
        $stmt = $pdo->prepare("UPDATE users 
            SET password_hash = ?, reset_token = NULL, reset_expires = NULL 
            WHERE id = ?");
        $stmt->execute([$hashed, $user['id']]);

        $successMessage = "✔ Your password has been updated successfully!";
        $showForm = false;
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo SITE_DIR; ?>">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
	<link rel="icon" type="image/x-icon" href="images/favicon.ico">
	<link href="https://fonts.googleapis.com/css2?family=Raleway:wght@300;400;600;700&display=swap" rel="stylesheet">
    <title>Reset Password - QuillTalk</title>

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
        }
        
        * { box-sizing: border-box; }

        .container {
            background: #fff;
            padding: 25px 30px;
            border-radius: 7px;
            max-width: 400px;
            width: 100%;
            margin: 40px auto;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        input {
            width: 100%;
            padding: 12px;
            margin: 8px 0;
            border-radius: 5px;
            border: 1px solid #ccc;
        }

        input:focus { border-color: #2d58f5; outline: none; }

        input[type=submit] {
            background-color: #2d58f5;
            color: white;
            cursor: pointer;
            border: none;
            margin-top: 17px;
        }

        .btn {
            background: #888;
            color: white;
            padding: 10px;
            display: block;
            margin-top: 15px;
            border-radius: 5px;
            text-align: center;
            text-decoration: none;
        }

        p { font-size: 15px; }
        
        .language-dropdown { position: relative; }
        .lang-button {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 5px 10px;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            min-width: 120px;
            border-radius: 5px;
            overflow: hidden;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
        }
        html[dir="rtl"] .dropdown-content {
            right: auto;
            left: 0;
        }
        .dropdown-content a {
            padding: 12px 16px;
            display: block;
            text-decoration: none;
            color: #333;
        }
        .dropdown-content a:hover { background-color: #ddd; }
        .language-dropdown:hover .dropdown-content { display: block; }
		
        a {
            text-decoration: none;
        }
        
        nav a {
            color: white;
            text-decoration: none;
            margin-left: 30px;
            font-size: 18px;
            font-weight: bold;
        }

        html[dir="rtl"] nav a {
            margin-left: 0;
            margin-right: 30px;
        }

        .nav-container {
            display: flex;
            gap: 25px;
            align-items: center;
        }

    </style>
</head>

<body>
<header>
    <a href="index.php"><img src="images/logo.png" style="height: 50px;"></a>

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
    <h2 style="text-align:center;margin-bottom:25px;">Reset Password</h2>

    <?php if (!empty($errorMessage)): ?>
        <p style="color:red;"><?php echo $errorMessage; ?></p>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <p style="color:green;"><?php echo $successMessage; ?></p>
        <a href="login.php" class="btn">Back To Login</a>
    <?php endif; ?>

    <?php if ($showForm): ?>
        <form method="POST" action="">
            <input type="password" name="new_password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <input type="submit" value="Reset Password">
        </form>
        <a href="login.php" class="btn">Back To Login</a>
    <?php endif; ?>
</div>

</body>
</html>
