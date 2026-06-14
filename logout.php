<?php
// CRITICAL: Must be at the very top to handle headers and sessions.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure the database connection is available for cleanup
// NOTE: This assumes your 'includes/db.php' file sets up the PDO connection ($pdo)
require __DIR__ . '/includes/db.php'; 

// --- 1. Aggressive Server-Side Cleanup (Database First) ---

// Check if a token exists in the session and DELETE it from the database
if (isset($_SESSION['quill_token'])) {
    $token_to_delete = $_SESSION['quill_token'];

    $userLookup = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $userLookup->execute([$token_to_delete]);
    $sessionRow = $userLookup->fetch(PDO::FETCH_ASSOC);
    if ($sessionRow && !empty($sessionRow['user_id'])) {
        $pdo->prepare("UPDATE users SET online = 0, last_seen_at = NOW() WHERE id = ?")
            ->execute([(int)$sessionRow['user_id']]);
    }
    
    // DELETE the session token from the database table
    // If the database row is gone, the session is invalidated even if the cookie exists.
    $stmt = $pdo->prepare("DELETE FROM sessions WHERE token = ?");
    $stmt->execute([$token_to_delete]);
    
    // Clear the specific session variable
    unset($_SESSION['quill_token']);
}

// 2. Remove ALL session variables
session_unset();

// 3. Destroy the session file on the server
session_destroy();

// 4. CRITICAL: Delete the session ID cookie itself (PHPSESSID)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// --- 2. Cookie Cleanup: Delete the persistent 'remember me' cookie ---
$delete_cookie_options = [
    'expires'  => time() - 3600, 
    'path'     => '/',
    'secure'   => true, 
    'httponly' => true,
    'samesite' => 'None'
];

setcookie('quill_remember', '', $delete_cookie_options);
setcookie('quill_remember', '', array_merge($delete_cookie_options, ['domain' => 'quilltalk.org']));
setcookie('quill_remember', '', array_merge($delete_cookie_options, ['domain' => '.quilltalk.org']));


// --- 3. Client-Side Cleanup and Redirect (Force JS Execution) ---
// We use a minimal HTML structure to ensure the browser runs the script.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Logging Out...</title>
</head>
<body>
    <p>Cleaning up...</p>
    <script>
        // Clears the ghost token from browser memory
        sessionStorage.removeItem('quill_token'); 
        localStorage.removeItem('quill_token');

        // Redirect after cleanup
        window.location.replace('login.php');
    </script>
</body>
</html>
<?php
exit;
?>
