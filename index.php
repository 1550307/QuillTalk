<?php
// --- FIX 1: Force HTTPS at the very top ---
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $location = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $location);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Language + DB ---
require_once __DIR__ . '/lang.php';
$lang = $_COOKIE['quill_language'] ?? 'en';
define('SITE_DIR', ($lang === 'ar' ? 'rtl' : 'ltr'));

// header("Access-Control-Allow-Origin: *"); // Optional: Remove if not needed for CORS

require __DIR__ . '/includes/db.php';

// --- FIX: Standard Cookie Options (Removed 'domain' for better compatibility) ---
$cookie_options = [
    'path'       => '/',
    'secure'     => true,
    'httponly'   => true,
    'samesite'   => 'None'
];


// -------------------------------------
// AUTO LOGIN USING SESSION OR REMEMBER COOKIE
// -------------------------------------

if (!empty($_SESSION['quill_token'])) {
    // ALREADY LOGGED IN VIA SESSION. CRITICAL FIX: VALIDATE TOKEN AGAINST DB.
    $token_to_check = $_SESSION['quill_token'];

    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ?");
    $stmt->execute([$token_to_check]);
    $session_data = $stmt->fetch();

    if ($session_data) {
        // Token is valid in the database: proceed to log in
        $token = $token_to_check;
        echo "<script>
            sessionStorage.setItem('quill_token', '$token');
            window.location.href = 'app.php?token=$token';
        </script>";
        exit;
    } else {
        // Token NOT found in database (it was deleted by logout.php):
        // Clean up the server session variable and FALL THROUGH to the login form.
        unset($_SESSION['quill_token']);
    }
}

if (isset($_COOKIE['quill_remember'])) {
    $rememberToken = $_COOKIE['quill_remember'];

    $stmt = $pdo->prepare("SELECT id FROM users WHERE remember_token = ?");
    $stmt->execute([$rememberToken]);
    $user = $stmt->fetch();

    if ($user) {
        // FIX: ROTATE REMEMBER TOKEN
        $newRemember = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")
             ->execute([$newRemember, $user['id']]);

        // Create a new session token for the user
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO sessions (token, user_id) VALUES (?, ?)")
             ->execute([$token, $user['id']]);
        
        // Refresh the 'quill_remember' cookie with the new token
        $cookie_options['expires'] = time() + 365 * 86400; // 1 year
        setcookie('quill_remember', $newRemember, $cookie_options); // Domain is implicitly current host

        // Set the session variable for immediate access
        $_SESSION['quill_token'] = $token;

        // Redirect user to the application
        echo "<script>
            sessionStorage.setItem('quill_token', '$token');
            window.location.href = 'app.php?token=$token';
        </script>";
        exit;
    } else {
        // FIX: Invalid token -> DELETE cookie with corrected parameters
        $cookie_options['expires'] = time() - 3600;
        setcookie('quill_remember', '', $cookie_options);
    }
}

$error = '';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo SITE_DIR; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="google-site-verification" content="Ze42Cgqn9hf6BH9wrgdMzOF8VSqcBjRwHIUQFuJZPW8" />
    <title>QuillTalk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <style>
        /* ... (Your original CSS styles are unchanged here) ... */
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            margin: 0;
            background-color: #f0f2f5;
            font-family: 'Raleway', sans-serif;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 999;
            background-color: #2d58f5;
            padding: 25px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 {
            font-size: 30px;
            color: white;
        }

        nav a {
            color: white;
            text-decoration: none;
            margin-left: 30px;
            font-size: 18px;
            font-weight: bold;
        }

        .top-section {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: flex-start;
            gap: 80px;
            padding: 80px 40px 40px;
            background-color: #ffffff;
        }

        .top-left {
            max-width: 500px;
            animation: slideIn 1s ease forwards;
        }

        .top-left h2 {
            font-size: 48px;
            color: #2d58f5;
            margin-bottom: 30px;
        }

        .signup-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .signup-form input,
        .signup-form button {
            padding: 16px;
            font-size: 16px;
            border-radius: 8px;
            border: 2px solid #ccc;
            opacity: 0.95;
        }

        .signup-form button {
            background-color: #2d58f5;
            color: white;
            font-weight: bold;
            border: none;
            transition: background 0.3s;
            cursor: pointer;
        }

        input:hover,
        button:hover {
            opacity: 1;
        }

        .ui-image img {
            width: 350px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            animation: float 3s ease-in-out infinite;
        }

        .why-quill {
            padding: 80px 30px;
            text-align: center;
            background-color: #f0f4ff;
        }

        .why-quill h2 {
            font-size: 36px;
            color: #2d58f5;
            margin-bottom: 40px;
        }

        .reasons {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 50px;
        }

        .reason {
            max-width: 280px;
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s;
        }

        .reason:hover {
            transform: translateY(-10px);
        }

        .reason h3 {
            color: #2d58f5;
            margin-bottom: 10px;
        }

        .reason p {
            font-size: 15px;
            color: #555;
        }

        footer {
            background-color: #e4ebf7;
            padding: 30px;
            text-align: center;
            color: #666;
            font-size: 14px;
        }

        @keyframes float {
            0%,
            100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
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

        :root {
            --ink: #050711;
            --ink-2: #090f22;
            --panel: rgba(255, 255, 255, 0.08);
            --panel-strong: rgba(255, 255, 255, 0.14);
            --line: rgba(255, 255, 255, 0.18);
            --text: #f7fbff;
            --muted: #aeb9d8;
            --blue: #6aa8ff;
            --cyan: #51f0ff;
            --violet: #c172ff;
            --rose: #ff6eb4;
            --gold: #ffd36a;
            --green: #6dffba;
            --shadow: 0 28px 90px rgba(0, 0, 0, 0.38);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            background: var(--ink);
        }

        body {
            display: block;
            min-height: 100vh;
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(circle at 12% 18%, #2d58f5 0%, rgba(106, 168, 255, 0.22) 20%, transparent 30%),
                radial-gradient(circle at 84% 12%, #2d58f5 0%, rgba(255, 110, 180, 0.18) 16%, transparent 25%),
                radial-gradient(circle at 55% 85%, #2d58f5 0%, rgba(109, 255, 186, 0.14) 20%, transparent 28%),
                linear-gradient(135deg, #2d58f5 0%, #050711 26%, #08122b 56%, #0f1021 100%);
            font-family: 'Raleway', sans-serif;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            background-image:
                linear-gradient(#2d58f5 0 1px, rgba(255, 255, 255, 0.025) 1px 2px, transparent 2px),
                linear-gradient(90deg, #2d58f5 0 1px, rgba(255, 255, 255, 0.025) 1px 2px, transparent 2px);
            background-size: 54px 54px;
            mask-image: linear-gradient(to bottom, #2d58f5 0%, rgba(0, 0, 0, 0.9) 16%, transparent 78%);
        }

        a {
            color: inherit;
        }

        .cinema-noise {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 1;
            opacity: 0.13;
            background:
                repeating-radial-gradient(circle at 8% 12%, #2d58f5 0 1px, rgba(255, 255, 255, 0.75) 1px 2px, transparent 2px 5px),
                repeating-linear-gradient(90deg, #2d58f5 0 1px, rgba(255, 255, 255, 0.08) 1px 2px, transparent 2px 4px);
            mix-blend-mode: soft-light;
            animation: grain 10s steps(8) infinite;
        }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 50;
            padding: 16px clamp(18px, 5vw, 70px);
            background: rgba(5, 7, 17, 0.76);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(22px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.28);
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: var(--text);
            text-decoration: none;
            font-weight: 900;
            letter-spacing: 0.02em;
        }

        .brand img {
            height: 48px;
            width: auto;
            object-fit: contain;
            filter: drop-shadow(0 0 20px rgba(106, 168, 255, 0.42));
        }

        .brand span {
            font-size: clamp(18px, 2vw, 24px);
        }

        .nav-container {
            gap: 18px;
        }

        .main-nav {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .main-nav a,
        html[dir="rtl"] .main-nav a {
            margin: 0;
        }

        .nav-link,
        .nav-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 15px;
            border-radius: 999px;
            color: rgba(255, 255, 255, 0.86);
            text-decoration: none;
            font-size: 14px;
            font-weight: 800;
            transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease, box-shadow 0.25s ease;
        }

        .nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .nav-cta {
            color: #071022;
            background: linear-gradient(135deg, #2d58f5 0%, var(--gold) 22%, #fff1b7 58%, var(--green) 100%);
            box-shadow: 0 14px 32px rgba(255, 211, 106, 0.24);
        }

        .nav-cta:hover {
            transform: translateY(-3px) scale(1.03);
            box-shadow: 0 18px 42px rgba(109, 255, 186, 0.28);
        }

        .language-dropdown {
            z-index: 10;
        }

        .lang-button {
            min-height: 42px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            backdrop-filter: blur(14px);
        }

        .dropdown-content {
            top: calc(100% + 10px);
            background: rgba(11, 18, 38, 0.96);
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
        }

        .dropdown-content a {
            color: #fff;
        }

        .dropdown-content a:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        main,
        footer {
            position: relative;
            z-index: 2;
        }

        .hero {
            min-height: calc(100vh - 86px);
            display: grid;
            grid-template-columns: minmax(0, 1.02fr) minmax(320px, 0.98fr);
            align-items: center;
            gap: clamp(34px, 5vw, 86px);
            padding: clamp(70px, 8vw, 118px) clamp(18px, 5vw, 82px) 68px;
            position: relative;
            overflow: hidden;
        }

        .hero::before,
        .hero::after {
            content: "";
            position: absolute;
            border-radius: 999px;
            filter: blur(2px);
            opacity: 0.72;
            pointer-events: none;
        }

        .hero::before {
            width: 38vw;
            height: 38vw;
            min-width: 330px;
            min-height: 330px;
            right: 2vw;
            top: 8vh;
            background: conic-gradient(from 140deg, #2d58f5, rgba(81, 240, 255, 0.2), rgba(193, 114, 255, 0.35), rgba(255, 211, 106, 0.18), #2d58f5);
            animation: slowSpin 28s linear infinite;
        }

        .hero::after {
            width: 26vw;
            height: 26vw;
            left: -8vw;
            bottom: -7vw;
            background: radial-gradient(circle, #2d58f5 0%, rgba(255, 110, 180, 0.22) 24%, transparent 68%);
            animation: breathe 7s ease-in-out infinite;
        }

        .hero-copy {
            position: relative;
            z-index: 3;
            max-width: 760px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            margin: 0 0 18px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 999px;
            color: #dce8ff;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
            font-size: 13px;
            font-weight: 900;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .eyebrow i {
            color: var(--gold);
            filter: drop-shadow(0 0 10px rgba(255, 211, 106, 0.6));
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(52px, 8vw, 108px);
            line-height: 0.88;
            letter-spacing: -0.08em;
            text-wrap: balance;
        }

        .hero h1 span {
            display: inline-block;
            color: transparent;
            background: linear-gradient(115deg, #2d58f5 0%, #fff 18%, #a9d5ff 44%, #ffd36a 70%, #ff8bc8 100%);
            -webkit-background-clip: text;
            background-clip: text;
            animation: titleGlow 4s ease-in-out infinite alternate;
        }

        .hero-subtitle {
            max-width: 660px;
            margin: 24px 0 0;
            color: #d3dcf5;
            font-size: clamp(18px, 2.1vw, 24px);
            line-height: 1.55;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 34px;
        }

        .primary-action,
        .secondary-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 56px;
            padding: 0 24px;
            border-radius: 18px;
            text-decoration: none;
            font-weight: 900;
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }

        .primary-action {
            color: #071022;
            background: linear-gradient(135deg, #2d58f5 0%, var(--gold) 22%, #fff8d8 58%, var(--green) 100%);
            box-shadow: 0 20px 50px rgba(255, 211, 106, 0.24);
        }

        .secondary-action {
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        .primary-action:hover,
        .secondary-action:hover {
            transform: translateY(-4px);
        }

        .secondary-action:hover {
            border-color: rgba(81, 240, 255, 0.5);
            box-shadow: 0 16px 38px rgba(81, 240, 255, 0.14);
        }

        .hero-proof {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 34px;
            max-width: 620px;
        }

        .proof-pill {
            position: relative;
            padding: 16px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.07);
            overflow: hidden;
        }

        .proof-pill::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent, #2d58f5, rgba(255, 255, 255, 0.13), transparent);
            transform: translateX(-100%);
            animation: shimmer 4.8s ease-in-out infinite;
        }

        .proof-pill strong {
            display: block;
            font-size: 26px;
            line-height: 1;
        }

        .proof-pill span {
            display: block;
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .hero-visual {
            position: relative;
            z-index: 3;
            min-height: 650px;
            perspective: 1200px;
        }

        .phone-stage {
            position: absolute;
            inset: 28px 16% auto auto;
            width: min(350px, 58vw);
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 42px;
            background: linear-gradient(145deg, #2d58f5 0%, rgba(255, 255, 255, 0.18) 32%, rgba(255, 255, 255, 0.06) 100%);
            box-shadow: var(--shadow), 0 0 70px rgba(106, 168, 255, 0.18);
            transform: rotateX(var(--tilt-y, 0deg)) rotateY(var(--tilt-x, 0deg)) rotateZ(-3deg);
            transform-style: preserve-3d;
            animation: phoneFloat 6s ease-in-out infinite;
        }

        .phone-stage::before {
            content: "";
            position: absolute;
            inset: -2px;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, #2d58f5 0%, rgba(81, 240, 255, 0.8) 26%, transparent 42%, rgba(255, 211, 106, 0.76) 72%, rgba(255, 110, 180, 0.72) 100%);
            -webkit-mask: linear-gradient(#2d58f5 0 0) content-box, linear-gradient(#2d58f5 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .phone-stage img {
            display: block;
            width: 100%;
            min-height: 520px;
            object-fit: cover;
            border-radius: 30px;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.14);
        }

        .floating-card {
            position: absolute;
            max-width: 260px;
            padding: 18px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 24px;
            background: rgba(8, 15, 34, 0.82);
            box-shadow: 0 22px 54px rgba(0, 0, 0, 0.28);
            backdrop-filter: blur(18px);
            animation: floatCard 5s ease-in-out infinite;
        }

        .floating-card i {
            color: var(--cyan);
            margin-right: 8px;
        }

        .floating-card strong {
            display: block;
            margin-bottom: 8px;
            font-size: 15px;
        }

        .floating-card p {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
            font-size: 13px;
        }

        .ai-float {
            top: 48px;
            left: 0;
        }

        .game-float {
            right: 0;
            bottom: 132px;
            animation-delay: -1.5s;
        }

        .slot-float {
            left: 4%;
            bottom: 24px;
            width: min(310px, 72vw);
            max-width: none;
            animation-delay: -2.5s;
        }

        .image-slot {
            position: relative;
            min-height: 220px;
            border: 1px dashed rgba(255, 255, 255, 0.34);
            border-radius: 28px;
            background:
                linear-gradient(135deg, #2d58f5 0%, rgba(255, 255, 255, 0.1) 32%, rgba(255, 255, 255, 0.03) 100%),
                repeating-linear-gradient(-45deg, #2d58f5 0 1px, rgba(255, 255, 255, 0.06) 1px 3px, transparent 3px 12px);
            overflow: hidden;
        }

        .image-slot::before,
        .image-slot::after {
            content: "";
            position: absolute;
            width: 58px;
            height: 58px;
            border-color: rgba(81, 240, 255, 0.64);
            border-style: solid;
            pointer-events: none;
        }

        .image-slot::before {
            top: 16px;
            left: 16px;
            border-width: 2px 0 0 2px;
        }

        .image-slot::after {
            right: 16px;
            bottom: 16px;
            border-width: 0 2px 2px 0;
        }

        .slot-inner {
            position: relative;
            z-index: 2;
            min-height: inherit;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            gap: 8px;
            padding: 24px;
        }

        .slot-label {
            display: inline-flex;
            width: fit-content;
            padding: 7px 10px;
            border-radius: 999px;
            color: #071022;
            background: var(--gold);
            font-size: 11px;
            font-weight: 1000;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .slot-inner strong {
            font-size: 20px;
            line-height: 1.12;
        }

        .slot-inner p {
            max-width: 360px;
            margin: 0;
            color: #d5ddf4;
            font-size: 13px;
            line-height: 1.55;
        }

        .section {
            padding: clamp(70px, 8vw, 120px) clamp(18px, 5vw, 82px);
        }

        .section-header {
            max-width: 820px;
            margin-bottom: 34px;
        }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            color: var(--green);
            font-weight: 1000;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            font-size: 12px;
        }

        .section h2 {
            margin: 0;
            font-size: clamp(36px, 5vw, 70px);
            line-height: 0.96;
            letter-spacing: -0.055em;
        }

        .section-lede {
            margin: 18px 0 0;
            color: var(--muted);
            font-size: clamp(16px, 2vw, 20px);
            line-height: 1.6;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 18px;
        }

        .feature-tile {
            position: relative;
            min-height: 260px;
            grid-column: span 4;
            padding: 26px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 32px;
            background:
                linear-gradient(150deg, #2d58f5 0%, rgba(255, 255, 255, 0.13) 32%, rgba(255, 255, 255, 0.045) 100%),
                rgba(255, 255, 255, 0.04);
            overflow: hidden;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.24);
            transition: transform 0.35s ease, border-color 0.35s ease, box-shadow 0.35s ease;
        }

        .feature-tile.wide {
            grid-column: span 8;
        }

        .feature-tile.tall {
            min-height: 410px;
        }

        .feature-tile::before {
            content: "";
            position: absolute;
            inset: -60% -30% auto auto;
            width: 260px;
            height: 260px;
            border-radius: 999px;
            background: radial-gradient(circle, #2d58f5 0%, var(--tile-glow, rgba(81, 240, 255, 0.24)) 28%, transparent 68%);
            opacity: 0.9;
        }

        .feature-tile:hover {
            transform: translateY(-10px);
            border-color: rgba(81, 240, 255, 0.36);
            box-shadow: 0 32px 80px rgba(0, 0, 0, 0.34);
        }

        .feature-icon {
            width: 52px;
            height: 52px;
            display: inline-grid;
            place-items: center;
            border-radius: 18px;
            color: #071022;
            background: linear-gradient(135deg, #2d58f5 0%, var(--tile-accent, var(--cyan)) 46%, #fff 100%);
            box-shadow: 0 16px 34px rgba(81, 240, 255, 0.22);
            font-size: 21px;
        }

        .feature-tile h3 {
            position: relative;
            margin: 24px 0 12px;
            font-size: clamp(22px, 2.6vw, 34px);
            line-height: 1.02;
            letter-spacing: -0.035em;
        }

        .feature-tile p {
            position: relative;
            margin: 0;
            color: #c8d2ee;
            line-height: 1.65;
            font-size: 15px;
        }

        .micro-list {
            position: relative;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 22px;
            padding: 0;
            list-style: none;
        }

        .micro-list li {
            padding: 8px 10px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 999px;
            color: #ebf2ff;
            background: rgba(255, 255, 255, 0.07);
            font-size: 12px;
            font-weight: 800;
        }

        .marquee-wrap {
            position: relative;
            padding: 18px 0;
            border-block: 1px solid rgba(255, 255, 255, 0.12);
            overflow: hidden;
            background: rgba(255, 255, 255, 0.045);
        }

        .marquee-track {
            display: flex;
            width: max-content;
            gap: 16px;
            animation: marquee 34s linear infinite;
        }

        .marquee-track span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 18px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 999px;
            color: #fff;
            background: rgba(255, 255, 255, 0.08);
            font-size: 14px;
            font-weight: 900;
            white-space: nowrap;
        }

        .marquee-track i {
            color: var(--gold);
        }

        .storyboard {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .slot-card {
            min-height: 360px;
            border-radius: 34px;
        }

        .split-showcase {
            display: grid;
            grid-template-columns: minmax(0, 0.95fr) minmax(320px, 1.05fr);
            align-items: center;
            gap: clamp(26px, 5vw, 74px);
        }

        .chat-cinema {
            position: relative;
            min-height: 520px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 38px;
            background:
                radial-gradient(circle at 12% 16%, #2d58f5 0%, rgba(81, 240, 255, 0.14) 20%, transparent 28%),
                radial-gradient(circle at 92% 82%, #2d58f5 0%, rgba(255, 211, 106, 0.12) 24%, transparent 34%),
                rgba(255, 255, 255, 0.055);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .chat-cinema::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent 0 28%, #2d58f5 34%, rgba(255, 255, 255, 0.08), transparent 62% 100%);
            animation: sweep 5s ease-in-out infinite;
        }

        .mock-chat {
            position: absolute;
            inset: 30px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .bubble {
            width: fit-content;
            max-width: 76%;
            padding: 14px 16px;
            border-radius: 20px;
            color: #061024;
            background: #fff;
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.18);
            animation: bubblePop 5s ease-in-out infinite;
        }

        .bubble.ai {
            align-self: flex-start;
            color: #fff;
            background: linear-gradient(135deg, #2d58f5 0%, rgba(106, 168, 255, 0.95) 42%, rgba(193, 114, 255, 0.88) 100%);
            animation-delay: -1.3s;
        }

        .bubble.me {
            align-self: flex-end;
            background: linear-gradient(135deg, #2d58f5 0%, var(--gold) 42%, #fff5c6 100%);
            animation-delay: -2.2s;
        }

        .reply-tag {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.74);
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .call-strip {
            position: absolute;
            left: 30px;
            right: 30px;
            bottom: 30px;
            display: grid;
            grid-template-columns: 46px 1fr auto;
            align-items: center;
            gap: 14px;
            padding: 14px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 24px;
            background: rgba(5, 7, 17, 0.76);
            backdrop-filter: blur(16px);
        }

        .call-strip img {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            object-fit: cover;
        }

        .call-strip strong {
            display: block;
        }

        .call-strip span {
            color: var(--muted);
            font-size: 12px;
        }

        .pulse-dot {
            width: 14px;
            height: 14px;
            border-radius: 999px;
            background: var(--green);
            box-shadow: 0 0 0 0 rgba(109, 255, 186, 0.6);
            animation: pulse 1.8s ease-out infinite;
        }

        .timeline {
            display: grid;
            gap: 14px;
            margin-top: 28px;
        }

        .timeline-step {
            display: grid;
            grid-template-columns: 48px 1fr;
            gap: 16px;
            align-items: start;
            padding: 18px;
            border: 1px solid rgba(255, 255, 255, 0.13);
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.06);
        }

        .timeline-step span {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            border-radius: 16px;
            color: #061024;
            background: linear-gradient(135deg, #2d58f5 0%, var(--cyan) 52%, #fff 100%);
            font-weight: 1000;
        }

        .timeline-step h3 {
            margin: 0 0 6px;
            font-size: 18px;
        }

        .timeline-step p {
            margin: 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .final-cta {
            margin: 0 clamp(18px, 5vw, 82px) clamp(70px, 8vw, 120px);
            padding: clamp(38px, 6vw, 74px);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 44px;
            background:
                radial-gradient(circle at 16% 20%, #2d58f5 0%, rgba(255, 211, 106, 0.18) 18%, transparent 25%),
                radial-gradient(circle at 88% 20%, #2d58f5 0%, rgba(81, 240, 255, 0.16) 20%, transparent 28%),
                linear-gradient(135deg, #2d58f5 0%, rgba(255, 255, 255, 0.13) 34%, rgba(255, 255, 255, 0.05) 100%);
            box-shadow: var(--shadow);
            text-align: center;
            overflow: hidden;
        }

        .final-cta h2 {
            max-width: 850px;
            margin: 0 auto 18px;
            font-size: clamp(38px, 6vw, 78px);
            line-height: 0.95;
            letter-spacing: -0.06em;
        }

        .final-cta p {
            max-width: 690px;
            margin: 0 auto 28px;
            color: var(--muted);
            font-size: 18px;
            line-height: 1.6;
        }

        footer {
            padding: 34px clamp(18px, 5vw, 82px);
            color: rgba(255, 255, 255, 0.62);
            background: rgba(0, 0, 0, 0.24);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .reveal {
            opacity: 0;
            transform: translateY(36px) scale(0.985);
            transition: opacity 0.85s ease, transform 0.85s ease;
            transition-delay: var(--delay, 0s);
        }

        .reveal.is-visible {
            opacity: 1;
            transform: none;
        }

        .blueprint-page {
            background:
                radial-gradient(circle at 8% 10%, #2d58f5 0%, rgba(45, 88, 245, 0.42) 24%, transparent 34%),
                radial-gradient(circle at 86% 4%, #2d58f5 0%, rgba(77, 214, 255, 0.24) 18%, transparent 26%),
                radial-gradient(circle at 52% 92%, #2d58f5 0%, rgba(45, 88, 245, 0.32) 26%, transparent 38%),
                linear-gradient(135deg, #2d58f5 0%, #020716 24%, #06184a 48%, #0d1f62 72%, #020716 100%);
        }

        .blueprint-page .site-header {
            background: linear-gradient(135deg, #2d58f5 0%, rgba(4, 12, 38, 0.84) 46%, rgba(45, 88, 245, 0.22) 100%);
            border-bottom-color: rgba(100, 148, 255, 0.25);
        }

        .blueprint-page .brand img {
            filter: drop-shadow(0 0 22px rgba(45, 88, 245, 0.72));
        }

        .blueprint-page .nav-cta,
        .blueprint-page .primary-action {
            color: #ffffff;
            background: linear-gradient(135deg, #2d58f5 0%, #6aa8ff 52%, #33d7ff 100%);
            box-shadow: 0 18px 48px rgba(45, 88, 245, 0.42);
        }

        .blueprint-page .secondary-action {
            background: rgba(45, 88, 245, 0.14);
            border-color: rgba(120, 165, 255, 0.42);
        }

        .blueprint-page .hero::before {
            background: conic-gradient(from 140deg, #2d58f5, rgba(45, 88, 245, 0.68), rgba(77, 214, 255, 0.26), rgba(255, 255, 255, 0.2), #2d58f5);
        }

        .blueprint-page .hero::after {
            background: radial-gradient(circle, #2d58f5 0%, rgba(45, 88, 245, 0.42) 24%, transparent 68%);
        }

        .blueprint-page .hero h1 span,
        .feature-copy h2 span {
            background: linear-gradient(115deg, #ffffff 0%, #bcd2ff 28%, #2d58f5 58%, #55dcff 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .blueprint-page .eyebrow,
        .blueprint-page .section-kicker {
            color: #dce7ff;
        }

        .blueprint-page .eyebrow i,
        .blueprint-page .section-kicker i,
        .blueprint-page .marquee-track i {
            color: #7cc4ff;
            filter: drop-shadow(0 0 12px rgba(45, 88, 245, 0.85));
        }

        .blueprint-page .proof-pill,
        .feature-stat,
        .feature-mini {
            background: linear-gradient(145deg, #2d58f5 0%, rgba(45, 88, 245, 0.18) 36%, rgba(255, 255, 255, 0.055) 100%);
            border-color: rgba(125, 169, 255, 0.24);
        }

        .blueprint-page .phone-stage::before,
        .feature-shot::before {
            background: linear-gradient(135deg, #2d58f5 0%, rgba(45, 88, 245, 0.95) 26%, rgba(80, 218, 255, 0.7) 62%, rgba(255, 255, 255, 0.22) 82%, #2d58f5 100%);
        }

        .feature-sections {
            display: grid;
            gap: clamp(34px, 5vw, 72px);
            padding: clamp(54px, 7vw, 110px) clamp(18px, 5vw, 82px);
        }

        .feature-chapter {
            position: relative;
            min-height: min(840px, calc(100vh - 84px));
            display: grid;
            grid-template-columns: minmax(0, 0.9fr) minmax(340px, 1.1fr);
            align-items: center;
            gap: clamp(26px, 5vw, 78px);
            padding: clamp(34px, 5vw, 72px);
            border: 1px solid rgba(133, 173, 255, 0.22);
            border-radius: clamp(32px, 5vw, 58px);
            background:
                linear-gradient(145deg, #2d58f5 0%, rgba(45, 88, 245, 0.2) 34%, rgba(255, 255, 255, 0.045) 100%),
                radial-gradient(circle at var(--spot-x, 72%) var(--spot-y, 18%), #2d58f5 0%, rgba(77, 214, 255, 0.2) 22%, transparent 34%),
                rgba(5, 12, 34, 0.68);
            box-shadow: 0 36px 110px rgba(0, 0, 0, 0.38), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }

        .feature-chapter:nth-child(even) {
            grid-template-columns: minmax(340px, 1.1fr) minmax(0, 0.9fr);
        }

        .feature-chapter:nth-child(even) .feature-copy {
            order: 2;
        }

        .feature-chapter::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(90deg, transparent, #2d58f5, rgba(255, 255, 255, 0.07), transparent),
                repeating-linear-gradient(0deg, rgba(255, 255, 255, 0.025) 0 1px, #2d58f5 1px 2px, transparent 2px 26px);
            opacity: 0.45;
            transform: translateX(-55%);
            animation: sweep 6s ease-in-out infinite;
            pointer-events: none;
        }

        .feature-copy,
        .feature-shot {
            position: relative;
            z-index: 2;
        }

        .feature-number {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 9px 13px;
            border-radius: 999px;
            border: 1px solid rgba(130, 174, 255, 0.34);
            background: rgba(45, 88, 245, 0.18);
            color: #dbe7ff;
            font-size: 12px;
            font-weight: 1000;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .feature-copy h2 {
            margin: 20px 0 18px;
            font-size: clamp(36px, 5.2vw, 76px);
            line-height: 0.95;
            letter-spacing: -0.06em;
        }

        .feature-copy p {
            max-width: 670px;
            margin: 0;
            color: #c7d5ff;
            font-size: clamp(16px, 1.7vw, 20px);
            line-height: 1.65;
        }

        .feature-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 28px;
        }

        .feature-stat {
            padding: 16px;
            border: 1px solid rgba(125, 169, 255, 0.24);
            border-radius: 20px;
        }

        .feature-stat strong {
            display: block;
            color: #ffffff;
            font-size: 18px;
            line-height: 1.12;
        }

        .feature-stat span {
            display: block;
            margin-top: 7px;
            color: #9eb7ff;
            font-size: 12px;
            font-weight: 850;
            letter-spacing: 0.04em;
        }

        .feature-shot {
            min-height: clamp(360px, 58vw, 620px);
            border-radius: clamp(28px, 4vw, 48px);
            border: 1px dashed rgba(154, 188, 255, 0.44);
            background:
                radial-gradient(circle at 28% 22%, #2d58f5 0%, rgba(77, 214, 255, 0.22) 18%, transparent 28%),
                radial-gradient(circle at 76% 82%, #2d58f5 0%, rgba(45, 88, 245, 0.38) 24%, transparent 36%),
                linear-gradient(145deg, #2d58f5 0%, rgba(45, 88, 245, 0.2) 40%, rgba(255, 255, 255, 0.055) 100%),
                repeating-linear-gradient(-45deg, rgba(255, 255, 255, 0.055) 0 2px, #2d58f5 2px 3px, transparent 3px 14px);
            box-shadow: 0 32px 82px rgba(45, 88, 245, 0.18), inset 0 0 0 1px rgba(255, 255, 255, 0.07);
            overflow: hidden;
        }

        .feature-shot::before {
            content: "";
            position: absolute;
            inset: -2px;
            border-radius: inherit;
            padding: 1px;
            -webkit-mask: linear-gradient(#2d58f5 0 0) content-box, linear-gradient(#2d58f5 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .feature-shot::after {
            content: "";
            position: absolute;
            inset: 18px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: calc(clamp(28px, 4vw, 48px) - 10px);
            background:
                linear-gradient(rgba(255, 255, 255, 0.07) 1px, #2d58f5 1px 2px, transparent 2px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.07) 1px, #2d58f5 1px 2px, transparent 2px);
            background-size: 42px 42px;
            opacity: 0.56;
            pointer-events: none;
        }

        .placeholder-center {
            position: relative;
            z-index: 2;
            min-height: inherit;
            display: grid;
            place-items: center;
            text-align: center;
            padding: 28px;
        }

        .placeholder-center span {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 13px 17px;
            border-radius: 999px;
            background: rgba(4, 12, 38, 0.72);
            border: 1px solid rgba(154, 188, 255, 0.32);
            color: #dfe8ff;
            font-size: 12px;
            font-weight: 1000;
            letter-spacing: 0.13em;
            text-transform: uppercase;
            box-shadow: 0 18px 44px rgba(0, 0, 0, 0.22);
        }

        .feature-mini-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 26px;
        }

        .feature-mini {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 13px;
            border: 1px solid rgba(125, 169, 255, 0.24);
            border-radius: 999px;
            color: #eaf0ff;
            font-size: 13px;
            font-weight: 850;
        }

        .feature-mini i {
            color: #83cfff;
        }

        .feature-intro {
            padding-bottom: 0;
        }

        .blueprint-page .marquee-wrap {
            border-block-color: rgba(125, 169, 255, 0.18);
            background: linear-gradient(90deg, #2d58f5 0%, rgba(45, 88, 245, 0.16) 24%, rgba(4, 12, 38, 0.32) 50%, rgba(45, 88, 245, 0.16) 76%, #2d58f5 100%);
        }

        .blueprint-page .marquee-track span {
            background: rgba(45, 88, 245, 0.16);
            border-color: rgba(125, 169, 255, 0.22);
        }

        @keyframes grain {
            0%, 100% { transform: translate(0, 0); }
            10% { transform: translate(-1%, -1%); }
            20% { transform: translate(1%, 1%); }
            30% { transform: translate(-2%, 1%); }
            40% { transform: translate(2%, -1%); }
            50% { transform: translate(-1%, 2%); }
            60% { transform: translate(1%, -2%); }
            70% { transform: translate(-2%, -1%); }
            80% { transform: translate(2%, 1%); }
            90% { transform: translate(-1%, 1%); }
        }

        @keyframes slowSpin {
            to { transform: rotate(360deg); }
        }

        @keyframes breathe {
            0%, 100% { transform: scale(0.92); opacity: 0.45; }
            50% { transform: scale(1.08); opacity: 0.82; }
        }

        @keyframes titleGlow {
            from { filter: drop-shadow(0 0 0 rgba(81, 240, 255, 0)); }
            to { filter: drop-shadow(0 0 28px rgba(81, 240, 255, 0.24)); }
        }

        @keyframes shimmer {
            0%, 46% { transform: translateX(-120%); }
            70%, 100% { transform: translateX(120%); }
        }

        @keyframes phoneFloat {
            0%, 100% { translate: 0 0; }
            50% { translate: 0 -18px; }
        }

        @keyframes floatCard {
            0%, 100% { transform: translate3d(0, 0, 0) rotate(-1deg); }
            50% { transform: translate3d(0, -16px, 0) rotate(1.5deg); }
        }

        @keyframes marquee {
            to { transform: translateX(-50%); }
        }

        @keyframes sweep {
            0%, 44% { transform: translateX(-120%); }
            75%, 100% { transform: translateX(120%); }
        }

        @keyframes bubblePop {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(109, 255, 186, 0.6); }
            100% { box-shadow: 0 0 0 20px rgba(109, 255, 186, 0); }
        }

        @media (max-width: 1100px) {
            .hero,
            .split-showcase,
            .feature-chapter,
            .feature-chapter:nth-child(even) {
                grid-template-columns: 1fr;
            }

            .feature-chapter:nth-child(even) .feature-copy {
                order: 0;
            }

            .hero-visual {
                min-height: 720px;
            }

            .phone-stage {
                right: 50%;
                transform: translateX(50%) rotateZ(-2deg);
            }

            .feature-tile,
            .feature-tile.wide {
                grid-column: span 6;
            }

            .feature-shot {
                min-height: 460px;
            }
        }

        @media (max-width: 820px) {
            .site-header {
                position: relative;
                align-items: flex-start;
                gap: 16px;
                flex-direction: column;
            }

            .nav-container,
            html[dir="rtl"] .nav-container {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .main-nav {
                flex-wrap: wrap;
            }

            .main-nav a {
                flex: 1 1 auto;
            }

            .hero {
                min-height: auto;
                padding-top: 52px;
            }

            .hero-proof,
            .storyboard {
                grid-template-columns: 1fr;
            }

            .feature-sections {
                padding-inline: 16px;
            }

            .feature-chapter {
                min-height: auto;
                padding: 26px;
                border-radius: 32px;
            }

            .feature-stats {
                grid-template-columns: 1fr;
            }

            .hero-visual {
                min-height: 760px;
            }

            .ai-float,
            .game-float,
            .slot-float {
                position: relative;
                inset: auto;
                margin: 16px auto 0;
                width: min(100%, 360px);
            }

            .phone-stage {
                position: relative;
                inset: auto;
                margin: 0 auto;
                width: min(340px, 92vw);
            }

            .feature-tile,
            .feature-tile.wide {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 560px) {
            .hero h1 {
                font-size: clamp(45px, 16vw, 64px);
            }

            .hero-actions {
                flex-direction: column;
            }

            .primary-action,
            .secondary-action {
                width: 100%;
            }

            .section {
                padding-inline: 16px;
            }

            .feature-shot {
                min-height: 330px;
            }

            .call-strip {
                grid-template-columns: 42px 1fr;
            }

            .pulse-dot {
                display: none;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: 0.001ms !important;
            }

            .reveal {
                opacity: 1;
                transform: none;
            }
        }
    </style>
</head>

<body class="blueprint-page">
    <div class="cinema-noise" aria-hidden="true"></div>

    <header class="site-header">
        <a class="brand" href="index.php" aria-label="QuillTalk home">
            <img src="images/logo.png" alt="QuillTalk logo">
        </a>
        <div class="nav-container">
            <nav class="main-nav" aria-label="Primary navigation">
                <a class="nav-link" href="#features">Features</a>
                <a class="nav-link" href="#sidequests">Sidequests</a>
                <a class="nav-link" href="#scrolls">Scrolls</a>
                <a class="nav-cta" href="signup.php">Get started</a>
                <a class="nav-link" href="login.php"><?php echo __('login'); ?></a>
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

    <main>
        <section class="hero" id="top">
            <div class="hero-copy reveal">
                <p class="eyebrow"><i class="fas fa-wand-magic-sparkles"></i> The cinematic social messenger</p>
                <h1>Chat like the app is <span>alive.</span></h1>
                <p class="hero-subtitle">
                    QuillTalk is built around the familiar messenger people expect, then turns it cinematic with
                    in-thread AI, generated images, Scrolls, calls, polls, voice tools, and games that happen where friends already are.
                </p>
                <div class="hero-actions">
                    <a class="primary-action" href="signup.php">Get started <i class="fas fa-arrow-right"></i></a>
                    <a class="secondary-action" href="login.php"><i class="fas fa-right-to-bracket"></i> <?php echo __('login'); ?></a>
                </div>
                <div class="hero-proof" aria-label="QuillTalk feature highlights">
                    <div class="proof-pill">
                        <strong>Live</strong>
                        <span>Feature-rich chats</span>
                    </div>
                    <div class="proof-pill">
                        <strong>AI</strong>
                        <span>Inside real chats</span>
                    </div>
                    <div class="proof-pill">
                        <strong>Play</strong>
                        <span>Games, Scrolls, calls</span>
                    </div>
                </div>
            </div>

            <div class="hero-visual reveal" style="--delay: .15s">
                <div class="phone-stage" aria-label="QuillTalk chat preview">
                    <img src="images/ui-preview.png" alt="QuillTalk chat interface preview">
                </div>
                <div class="floating-card ai-float">
                    <strong><i class="fas fa-robot"></i> AI lives in the thread</strong>
                    <p>Slash commands, mentions, replies, and image prompts can all happen inside the normal chat flow.</p>
                </div>
                <div class="floating-card game-float">
                    <strong><i class="fas fa-gamepad"></i> Conversations can become moments</strong>
                    <p>Start a call, post a Scroll, create a poll, or launch a Sidequest without leaving QuillTalk.</p>
                </div>
            </div>
        </section>

        <div class="marquee-wrap" aria-hidden="true">
            <div class="marquee-track">
                <span><i class="fas fa-robot"></i> In-chat AI</span>
                <span><i class="fas fa-image"></i> AI image improvise</span>
                <span><i class="fas fa-brain"></i> Custom AI chats</span>
                <span><i class="fas fa-gamepad"></i> Sidequests</span>
                <span><i class="fas fa-scroll"></i> Scrolls</span>
                <span><i class="fas fa-phone-volume"></i> Smart calls</span>
                <span><i class="fas fa-chart-simple"></i> Polls</span>
                <span><i class="fas fa-bolt"></i> Message superpowers</span>
                <span><i class="fas fa-robot"></i> In-chat AI</span>
                <span><i class="fas fa-image"></i> AI image improvise</span>
                <span><i class="fas fa-brain"></i> Custom AI chats</span>
                <span><i class="fas fa-gamepad"></i> Sidequests</span>
                <span><i class="fas fa-scroll"></i> Scrolls</span>
                <span><i class="fas fa-phone-volume"></i> Smart calls</span>
                <span><i class="fas fa-chart-simple"></i> Polls</span>
                <span><i class="fas fa-bolt"></i> Message superpowers</span>
            </div>
        </div>

        <section class="section feature-intro" id="features">
            <div class="section-header reveal">
                <span class="section-kicker"><i class="fas fa-clapperboard"></i> Feature Chapters</span>
                <h2>Every major QuillTalk feature gets its own scroll scene.</h2>
                <p class="section-lede">
                    Each scroll stop has a cinematic visual frame ready for real app screenshots, so the page can grow
                    into a polished product showcase as your final assets arrive.
                </p>
            </div>
        </section>

        <div class="feature-sections">
            <section class="feature-chapter reveal" id="ai-command" style="--spot-x: 82%; --spot-y: 18%;">
                <div class="feature-copy">
                    <span class="feature-number">01 / In-chat AI</span>
                    <h2>Summon <span>@QuillTalk AI</span> without leaving the conversation.</h2>
                    <p>
                        Use slash commands, mention QuillTalk AI, or reply directly to an AI message. The assistant answers
                        in context, attached to the exact conversation that sparked it.
                    </p>
                    <div class="feature-stats">
                        <div class="feature-stat"><strong>/ai</strong><span>Slash commands</span></div>
                        <div class="feature-stat"><strong>@AI</strong><span>Mention trigger</span></div>
                        <div class="feature-stat"><strong>Reply</strong><span>Thread context</span></div>
                    </div>
                    <div class="feature-mini-row">
                        <span class="feature-mini"><i class="fas fa-at"></i> Mention autocomplete</span>
                        <span class="feature-mini"><i class="fas fa-reply"></i> Reply-aware answers</span>
                        <span class="feature-mini"><i class="fas fa-comments"></i> Native chat bubbles</span>
                    </div>
                </div>
                <div class="feature-shot">
                    <!-- IMAGE GUIDE: Replace this placeholder with a polished in-app screenshot of a real QuillTalk chat where a user mentions @QuillTalk AI, the mention appears in the message composer/autocomplete flow, and the AI reply appears directly under the user's message as a reply bubble. Show the app in action, preferably in a phone mockup or tall mobile crop, with brand-colored interface elements visible. -->
                    <div class="placeholder-center"><span><i class="fas fa-image" aria-hidden="true"></i></span></div>
                </div>
            </section>

            <section class="feature-chapter reveal" id="ai-images" style="--spot-x: 20%; --spot-y: 20%;">
                <div class="feature-copy">
                    <span class="feature-number">02 / AI Images</span>
                    <h2>Turn a chat idea into a <span>generated image</span>.</h2>
                    <p>
                        QuillTalk can improvise visuals from the flow of a conversation, then place the generated image back
                        where everyone is already talking.
                    </p>
                    <div class="feature-stats">
                        <div class="feature-stat"><strong>Prompt</strong><span>From chat context</span></div>
                        <div class="feature-stat"><strong>Image</strong><span>Generated in-flow</span></div>
                        <div class="feature-stat"><strong>Reply</strong><span>Attached result</span></div>
                    </div>
                    <div class="feature-mini-row">
                        <span class="feature-mini"><i class="fas fa-wand-magic-sparkles"></i> Improvise button</span>
                        <span class="feature-mini"><i class="fas fa-paintbrush"></i> Creative prompts</span>
                        <span class="feature-mini"><i class="fas fa-paper-plane"></i> Sent like a message</span>
                    </div>
                </div>
                <div class="feature-shot">
                    <!-- IMAGE GUIDE: Replace this placeholder with an in-app screenshot showing the AI image improvise flow: a user prompt or message, the tilted send/improvise button if visible, and the generated image appearing in the conversation as a reply to the user. Make the image feel like an active product screenshot, not a generic art poster. -->
                    <div class="placeholder-center"><span><i class="fas fa-image" aria-hidden="true"></i></span></div>
                </div>
            </section>

            <section class="feature-chapter reveal" id="custom-ai" style="--spot-x: 78%; --spot-y: 28%;">
                <div class="feature-copy">
                    <span class="feature-number">03 / Custom AI Chats</span>
                    <h2>Build a private <span>AI room</span> for ideas, memory, and experiments.</h2>
                    <p>
                        Dedicated AI chats give QuillTalk another mode: a place to brainstorm, test prompts, and keep AI
                        conversations separate from friend threads when that is the better fit.
                    </p>
                    <div class="feature-stats">
                        <div class="feature-stat"><strong>Rooms</strong><span>AI chat spaces</span></div>
                        <div class="feature-stat"><strong>Memory</strong><span>Clearable context</span></div>
                        <div class="feature-stat"><strong>Local</strong><span>Focused sessions</span></div>
                    </div>
                    <div class="feature-mini-row">
                        <span class="feature-mini"><i class="fas fa-brain"></i> AI memory</span>
                        <span class="feature-mini"><i class="fas fa-pen"></i> Rename/edit chats</span>
                        <span class="feature-mini"><i class="fas fa-layer-group"></i> Separate from DMs</span>
                    </div>
                </div>
                <div class="feature-shot">
                    <!-- IMAGE GUIDE: Replace this placeholder with a screenshot of the custom AI chat area, showing a list of AI chats or a focused AI conversation with QuillTalk's branded UI. Ideally include a visible AI avatar/name, a recent prompt, and a useful response so it looks like an actual AI workspace inside the app. -->
                    <div class="placeholder-center"><span><i class="fas fa-image" aria-hidden="true"></i></span></div>
                </div>
            </section>

            <section class="feature-chapter reveal" id="sidequests" style="--spot-x: 18%; --spot-y: 74%;">
                <div class="feature-copy">
                    <span class="feature-number">04 / Sidequests</span>
                    <h2>When the chat gets quiet, <span>start a game</span>.</h2>
                    <p>
                        Chess, Checkers, Connect Four, and Sketchoff make QuillTalk feel less like a static inbox and more
                        like a shared room where friends can actually do something together.
                    </p>
                    <div class="feature-stats">
                        <div class="feature-stat"><strong>Chess</strong><span>Strategic matches</span></div>
                        <div class="feature-stat"><strong>Connect 4</strong><span>Fast rounds</span></div>
                        <div class="feature-stat"><strong>Sketchoff</strong><span>Draw together</span></div>
                    </div>
                    <div class="feature-mini-row">
                        <span class="feature-mini"><i class="fas fa-chess-knight"></i> Chess</span>
                        <span class="feature-mini"><i class="fas fa-circle-dot"></i> Checkers</span>
                        <span class="feature-mini"><i class="fas fa-table-cells"></i> Connect Four</span>
                        <span class="feature-mini"><i class="fas fa-palette"></i> Sketchoff</span>
                    </div>
                </div>
                <div class="feature-shot">
                    <!-- IMAGE GUIDE: Replace this placeholder with an in-app action screenshot of a Sidequest running inside QuillTalk. Best option: a phone mockup showing a chat beside or above an active Chess, Checkers, Connect Four, or Sketchoff board, with player names and game chat visible so visitors understand the game is built into the conversation. -->
                    <div class="placeholder-center"><span><i class="fas fa-image" aria-hidden="true"></i></span></div>
                </div>
            </section>

            <section class="feature-chapter reveal" id="scrolls" style="--spot-x: 82%; --spot-y: 70%;">
                <div class="feature-copy">
                    <span class="feature-number">05 / Scrolls</span>
                    <h2>A social feed lives <span>inside</span> the messenger.</h2>
                    <p>
                        Scrolls give QuillTalk public-feeling energy without leaving the app: posts, comments, replies,
                        reactions, and follows all sit beside the chat experience.
                    </p>
                    <div class="feature-stats">
                        <div class="feature-stat"><strong>Posts</strong><span>Share moments</span></div>
                        <div class="feature-stat"><strong>Replies</strong><span>Threaded comments</span></div>
                        <div class="feature-stat"><strong>React</strong><span>Social feedback</span></div>
                    </div>
                    <div class="feature-mini-row">
                        <span class="feature-mini"><i class="fas fa-scroll"></i> Scroll feed</span>
                        <span class="feature-mini"><i class="fas fa-comment-dots"></i> Comments</span>
                        <span class="feature-mini"><i class="fas fa-heart"></i> Reactions</span>
                        <span class="feature-mini"><i class="fas fa-user-plus"></i> Follows</span>
                    </div>
                </div>
                <div class="feature-shot">
                    <!-- IMAGE GUIDE: Replace this placeholder with a real Scrolls feed screenshot from QuillTalk. Show a post with the author's profile image, reaction controls, comments, and at least one reply visible. The image should clearly show Scrolls as an in-app social feed, not just a generic feed mockup. -->
                    <div class="placeholder-center"><span><i class="fas fa-image" aria-hidden="true"></i></span></div>
                </div>
            </section>

            <section class="feature-chapter reveal" id="calls" style="--spot-x: 22%; --spot-y: 20%;">
                <div class="feature-copy">
                    <span class="feature-number">06 / Smart Calls</span>
                    <h2>Voice and calls can become <span>useful memory</span>.</h2>
                    <p>
                        Call invites, group calling, voice transcription, and AI follow-ups help fast conversations become
                        something people can search, summarize, and act on later.
                    </p>
                    <div class="feature-stats">
                        <div class="feature-stat"><strong>Calls</strong><span>Group moments</span></div>
                        <div class="feature-stat"><strong>Voice</strong><span>Transcription</span></div>
                        <div class="feature-stat"><strong>AI</strong><span>Summaries</span></div>
                    </div>
                    <div class="feature-mini-row">
                        <span class="feature-mini"><i class="fas fa-phone-volume"></i> Call invites</span>
                        <span class="feature-mini"><i class="fas fa-microphone-lines"></i> Voice notes</span>
                        <span class="feature-mini"><i class="fas fa-file-lines"></i> Transcripts</span>
                        <span class="feature-mini"><i class="fas fa-robot"></i> AI follow-ups</span>
                    </div>
                </div>
                <div class="feature-shot">
                    <!-- IMAGE GUIDE: Replace this placeholder with an app-in-action screenshot showing a QuillTalk call or group call moment next to a voice transcript or AI-generated call summary. If possible, include the call invite state and a short transcript/summary preview so the feature feels practical. -->
                    <div class="placeholder-center"><span><i class="fas fa-image" aria-hidden="true"></i></span></div>
                </div>
            </section>

            <section class="feature-chapter reveal" id="polls" style="--spot-x: 76%; --spot-y: 24%;">
                <div class="feature-copy">
                    <span class="feature-number">07 / Group Decisions</span>
                    <h2>Polls and scheduling keep groups <span>from spiraling</span>.</h2>
                    <p>
                        QuillTalk gives busy chats structure with polls, image options, scheduled messages, read receipts,
                        group roles, nicknames, and notification preferences.
                    </p>
                    <div class="feature-stats">
                        <div class="feature-stat"><strong>Polls</strong><span>Fast choices</span></div>
                        <div class="feature-stat"><strong>Schedule</strong><span>Send later</span></div>
                        <div class="feature-stat"><strong>Groups</strong><span>Roles + settings</span></div>
                    </div>
                    <div class="feature-mini-row">
                        <span class="feature-mini"><i class="fas fa-chart-simple"></i> Polls</span>
                        <span class="feature-mini"><i class="fas fa-clock"></i> Scheduled sends</span>
                        <span class="feature-mini"><i class="fas fa-eye"></i> Read receipts</span>
                        <span class="feature-mini"><i class="fas fa-users-gear"></i> Group controls</span>
                    </div>
                </div>
                <div class="feature-shot">
                    <!-- IMAGE GUIDE: Replace this placeholder with an in-app screenshot of a group chat where a poll is active. Show poll options, votes or voter preview, and surrounding chat messages. A strong image would also include the scheduled message UI or group settings drawer in a side-by-side collage. -->
                    <div class="placeholder-center"><span><i class="fas fa-image" aria-hidden="true"></i></span></div>
                </div>
            </section>

            <section class="feature-chapter reveal" id="message-tools" style="--spot-x: 20%; --spot-y: 72%;">
                <div class="feature-copy">
                    <span class="feature-number">08 / Message Superpowers</span>
                    <h2>Small tools make every message <span>more expressive</span>.</h2>
                    <p>
                        Translation, GIF search, reactions, nicknames, message search, focus states, blocking, and notification
                        preferences make QuillTalk feel personal without turning the interface into a maze.
                    </p>
                    <div class="feature-stats">
                        <div class="feature-stat"><strong>Translate</strong><span>Cross-language chats</span></div>
                        <div class="feature-stat"><strong>React</strong><span>Fast emotional tone</span></div>
                        <div class="feature-stat"><strong>Search</strong><span>Find old moments</span></div>
                    </div>
                    <div class="feature-mini-row">
                        <span class="feature-mini"><i class="fas fa-language"></i> Translation</span>
                        <span class="feature-mini"><i class="fas fa-film"></i> GIF search</span>
                        <span class="feature-mini"><i class="fas fa-face-smile"></i> Reactions</span>
                        <span class="feature-mini"><i class="fas fa-magnifying-glass"></i> Message search</span>
                    </div>
                </div>
                <div class="feature-shot">
                    <!-- IMAGE GUIDE: Replace this placeholder with a collage-style in-app screenshot showing message action tools in use: a translated message, a GIF search result, reaction picker/reactions, nickname display, and search results. Keep it rooted in QuillTalk UI with real chat bubbles and brand-colored accents. -->
                    <div class="placeholder-center"><span><i class="fas fa-image" aria-hidden="true"></i></span></div>
                </div>
            </section>
        </div>

        <section class="final-cta reveal" id="get-started">
            <h2>Make every conversation feel alive.</h2>
            <p>
                Keep signup and login simple. Let the scroll tell the story: AI, images, custom AI rooms,
                Sidequests, Scrolls, smart calls, group decisions, and message tools.
            </p>
            <div class="hero-actions" style="justify-content: center;">
                <a class="primary-action" href="signup.php">Get started <i class="fas fa-arrow-right"></i></a>
                <a class="secondary-action" href="login.php"><i class="fas fa-right-to-bracket"></i> <?php echo __('login'); ?></a>
            </div>
        </section>
    </main>

    <footer>
        &copy; <?php echo date("Y"); ?> <?php echo __('footer_slogan'); ?>
    </footer>

    <script>
        const revealItems = document.querySelectorAll('.reveal');
        const motionAllowed = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if ('IntersectionObserver' in window) {
            const revealObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        revealObserver.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.16
            });

            revealItems.forEach((item) => revealObserver.observe(item));
        } else {
            revealItems.forEach((item) => item.classList.add('is-visible'));
        }

        if (motionAllowed) {
            const phoneStage = document.querySelector('.phone-stage');
            window.addEventListener('pointermove', (event) => {
                if (!phoneStage) return;
                const tiltX = ((event.clientX / window.innerWidth) - 0.5) * 10;
                const tiltY = ((event.clientY / window.innerHeight) - 0.5) * -8;
                phoneStage.style.setProperty('--tilt-x', `${tiltX}deg`);
                phoneStage.style.setProperty('--tilt-y', `${tiltY}deg`);
            });
        }

        // Language switching
        document.querySelectorAll('.dropdown-content a').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const lang = this.getAttribute('data-lang');
                
                // FIX: Add domain to language cookie for consistency
                // NOTE: Using the exact domain 'quilltalk.org' might cause issues if you are running on 'localhost' 
                // or a different domain. It's safer to omit the domain unless absolutely required.
                document.cookie = `quill_language=${lang}; path=/; max-age=${60*60*24*365}`;

                // Force a full reload so PHP sees the new cookie
                window.location.href = window.location.href.split('#')[0];
            });
        });
    </script>
</body>
</html>
