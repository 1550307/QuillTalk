<?php
// Start the session just in case it holds other user information
session_start();

// --- Database Connection ---
// This file is assumed to define and initialize the $pdo connection object.
require_once 'includes/db.php'; 
// --- End Database Connection ---


// --- 1. User Identification via Token (Matches your app.php logic) ---
// Get the authentication token from the URL, which the button passes via the form method="GET".
$token = $_GET['token'] ?? '';

if (!$token) {
    // Redirect if the token is missing (safety measure)
    header("Location: login.php?error=missing_token");
    exit();
}

try {
    // Validate the token against the database and retrieve the user's ID
    $stmt = $pdo->prepare("
        SELECT users.id
        FROM sessions
        JOIN users ON sessions.user_id = users.id
        WHERE sessions.token = ?
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        // Redirect if the token is invalid or expired
        header("Location: login.php?error=invalid_session");
        exit();
    }

    $user_id = $user['id'];
    
    // START DATABASE TRANSACTION: If any query fails, all changes are rolled back.
    $pdo->beginTransaction();

    // --- 2. Data Deletion (Order matters: related data first) ---
    
    // 2a. Delete all messages sent by OR received by this user
    // Uses the correct column name 'recipient_id'
    $stmt_messages = $pdo->prepare("
        DELETE FROM messages
        WHERE sender_id = ? OR recipient_id = ?
    ");
    $stmt_messages->execute([$user_id, $user_id]);

    // 2b. Delete all friend relationships
    $stmt_friends = $pdo->prepare("
        DELETE FROM friends
        WHERE user_id = ? OR friend_id = ?
    ");
    $stmt_friends->execute([$user_id, $user_id]);

    // 2c. Delete the session record using the token's user_id (logging the user out of all sessions)
    $stmt_session = $pdo->prepare("
        DELETE FROM sessions
        WHERE user_id = ?
    ");
    $stmt_session->execute([$user_id]);

    // 2d. Delete the core user record from the 'users' table
    $stmt_user = $pdo->prepare("
        DELETE FROM users
        WHERE id = ?
    ");
    $stmt_user->execute([$user_id]);

    // Commit the transaction only if ALL steps succeeded
    $pdo->commit();

    // --- 3. LOGOUT AND REDIRECT ---
    
    // Clear any lingering session data
    $_SESSION = [];
    session_destroy();
    
    // Prevent the browser from caching this page
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");

    // Redirect to the login page with a success status
    header("Location: login.php?status=account_deleted");
    exit();

} catch (Exception $e) {
    // Roll back if any query failed
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log the error internally
    error_log("Account deletion failed. Error: " . $e->getMessage());
    
    // Friendly error message for the user
    die("A technical problem occurred while deleting your account. Please try again later.");
}

?>
