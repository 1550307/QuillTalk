<?php
// init.php

// 1. Ensure the session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 2. Include the translation logic (must be in the same directory)
require_once __DIR__ . '/lang.php'; 

// 3. Set the global language variable from the cookie, defaulting to English
$lang = isset($_COOKIE['quill_language']) ? $_COOKIE['quill_language'] : 'en';

// 4. (Optional) Set a global constant for the site direction (RTL/LTR)
define('SITE_DIR', ($lang == 'ar' ? 'rtl' : 'ltr'));

// 5. Set the Access-Control-Allow-Origin header (if you need it universally)
header("Access-Control-Allow-Origin: *");

// Note: The $lang variable and the __() function are now available globally 
// before any of your page code executes.
?>
