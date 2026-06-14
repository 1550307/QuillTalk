<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
require 'phpmailer/Exception.php';

require __DIR__ . '/quilltalk-backend/vendor/autoload.php';
require_once __DIR__ . '/lang.php';
require __DIR__ . '/includes/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$lang = isset($_COOKIE['quill_language']) ? $_COOKIE['quill_language'] : 'en';
define('SITE_DIR', ($lang == 'ar' ? 'rtl' : 'ltr'));

$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);

    // 1️⃣ Check if email exists
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $errorMessage = "❌ This email is not registered.";
    } else {
        // 2️⃣ Generate secure reset token
        $token = bin2hex(random_bytes(32));
        $expires = date("Y-m-d H:i:s", time() + 3600); // 1 hour expiration

        // 3️⃣ Store token in database
        $stmt = $pdo->prepare("UPDATE users SET reset_token=?, reset_expires=? WHERE email=?");
        $stmt->execute([$token, $expires, $email]);

        // 4️⃣ Create full reset link
        $resetLink = "https://quilltalk.org/reset_password.php?token=" . urlencode($token);

        // 5️⃣ SEND EMAIL USING PHPMailer (Mailjet SMTP)
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'in-v3.mailjet.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = '7e8fccae9161da8d7c240c379532756d';
            $mail->Password   = '7b0d2ccc3317330e1aa4e11563bed8d3';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // From + To
            $mail->setFrom('noreply@quilltalk.org', 'QuillTalk');
            $mail->addAddress($email, $user['username']);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'QuillTalk Password Reset';
            $mail->Body = "
                <p>Hey {$user['username']},</p>
                <p>You requested a password reset.</p>
                <p>Click below to reset your password:</p>
                <p><a href='$resetLink'>$resetLink</a></p>
                <p>If you didn't request this, ignore this email.</p>
            ";

            $mail->send();
            $successMessage = "✔ An email was sent to $email with instructions on how to reset your password.";

        } catch (Exception $e) {
            $errorMessage = "❌ Email failed: " . $mail->ErrorInfo;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo SITE_DIR; ?>">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
	<link rel="icon" type="image/x-icon" href="images/favicon.ico">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Raleway:wght@300;400;600;700&display=swap" rel="stylesheet">
    <title><?php echo __('forgot_password'); ?> - QuillTalk</title>

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
            position: relative;
            border-radius: 5px;
            background-color: #ffffff;
            padding: 20px 30px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            margin: 25px auto 40px;
            text-align: center;
        }

        html[dir="rtl"] .container { text-align: right; }

        input, .btn {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin: 8px 0;
            font-size: 16px;
            display: block;
            opacity: 0.95;
            transition: opacity 0.3s ease;
        }

        input:hover, .btn:hover { opacity: 1; }

        input[type=submit] {
            background-color: #2d58f5;
            border: none;
            color: white;
            cursor: pointer;
            margin-top: 20px;
        }

        .btn {
            background-color: #777;
            color: white;
            border: none;
            text-align: center;
            padding: 10px;
            cursor: pointer;
        }

        input:focus {
            border-color: #2d58f5;
            outline: none;
        }

        html[dir="rtl"] input { text-align: right; }

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
    <h2 style="color:#333; margin-bottom:20px;"><?php echo __('forgot_password'); ?></h2>
	
    <form method="POST" action="#">
        <input type="email" name="email" placeholder="Email" required>
        <input type="submit" value="Submit">
        <?php if (!empty($successMessage)): ?>
            <p style="color: green; font-size: 15px; margin-bottom: 15px;">
                <?php echo $successMessage; ?>
            </p>
        <?php endif; ?>
        
        <?php if (!empty($errorMessage)): ?>
            <p style="color: red; font-size: 15px; margin-bottom: 15px;">
                <?php echo $errorMessage; ?>
            </p>
        <?php endif; ?>
        <a href="login.php" class="btn">Back To Login</a>
    </form>
</div>

<script>
document.querySelectorAll('.dropdown-content a').forEach(item => {
  item.addEventListener('click', function(e) {
    e.preventDefault();
    const lang = this.getAttribute('data-lang');
    document.cookie = `quill_language=${lang}; expires=${new Date(Date.now()+365*24*60*60*1000).toUTCString()}; path=/`;
    window.location.reload();
  });
});
</script>

</body>
</html>
