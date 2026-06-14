<?php
require __DIR__ . '/includes/db.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$user_id = $_SESSION['user_id'];
// Generate a unique random suffix (using time and a random string)
$random_suffix = bin2hex(random_bytes(3)); 
$new_peer_alias = "user_" . $user_id . "_" . $random_suffix;

// Update the 'peer_id_alias' column in your users table
// NOTE: Make sure you ran: ALTER TABLE users ADD COLUMN peer_id_alias VARCHAR(100) DEFAULT NULL;
$sql = "UPDATE users SET peer_id_alias = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $new_peer_alias, $user_id);

if ($stmt->execute()) {
    // Redirect back to your chat page
    header("Location: chat_page.php?reset=success");
} else {
    echo "Error updating connection: " . $conn->error;
}
exit();
