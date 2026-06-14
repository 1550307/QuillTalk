<?php
// Set CORS header to allow requests from any origin.
// This is crucial for cross-origin fetching by your JavaScript frontend.
// For production, consider replacing '*' with your specific frontend domain(s)
// e.g., header("Access-Control-Allow-Origin: https://your-chat-app.com");
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include your database connection file
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

// Get the token from the GET request
$token = $_GET['token'] ?? '';

// If no token is provided, return an empty JSON array and exit
if (!$token) {
    echo json_encode([]);
    exit;
}

try {
    $sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $sessionStmt->execute([$token]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode([]);
        exit;
    }

    $userId = (int)($session['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode([]);
        exit;
    }

    $contacts = qt_fetch_all_contact_rows($pdo, $userId);

    // Encode the contacts array as JSON and output it
    echo json_encode($contacts, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // Catch any database connection or query errors
    // For debugging, you can echo the error, but in production, you might log it
    // and return a generic error message or empty array.
    error_log("Database error in fetch_contacts.php: " . $e->getMessage());
    echo json_encode([], JSON_UNESCAPED_UNICODE); // Return empty array on error
}
?>
