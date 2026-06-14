<?php
/**
 * Script to regenerate all usernames based on display names
 * This fixes the issue where usernames were generated as "User###" instead of "(DisplayName)###"
 * 
 * USAGE: Visit this file in your browser: https://quilltalk.org/fix_usernames.php
 * For security, this script will only run once. Delete it after use.
 */

require_once __DIR__ . '/includes/db.php';

// Security check - only allow running once
$lock_file = __DIR__ . '/fix_usernames.lock';
if (file_exists($lock_file)) {
    die('This script has already been run. Delete fix_usernames.lock to run again.');
}

echo "<h1>Username Regeneration Script</h1>";
echo "<p>Starting username regeneration...</p>";

try {
    // Get all users
    $stmt = $pdo->query("SELECT id, username, display_name FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found " . count($users) . " users to process.</p>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Display Name</th><th>Old Username</th><th>New Username</th><th>Status</th></tr>";
    
    $updated = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ($users as $user) {
        $userId = $user['id'];
        $displayName = $user['display_name'];
        $oldUsername = $user['username'];
        
        try {
            // Generate new username using the same function as signup
            $newUsername = generate_unique_username($pdo, $displayName);
            
            // Only update if different
            if ($newUsername !== $oldUsername) {
                $updateStmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                $updateStmt->execute([$newUsername, $userId]);
                
                echo "<tr>";
                echo "<td>{$userId}</td>";
                echo "<td>" . htmlspecialchars($displayName) . "</td>";
                echo "<td>" . htmlspecialchars($oldUsername) . "</td>";
                echo "<td>" . htmlspecialchars($newUsername) . "</td>";
                echo "<td style='color: green;'>✓ Updated</td>";
                echo "</tr>";
                
                $updated++;
            } else {
                echo "<tr>";
                echo "<td>{$userId}</td>";
                echo "<td>" . htmlspecialchars($displayName) . "</td>";
                echo "<td colspan='2'>" . htmlspecialchars($oldUsername) . "</td>";
                echo "<td style='color: gray;'>- Skipped (already correct)</td>";
                echo "</tr>";
                
                $skipped++;
            }
        } catch (Exception $e) {
            echo "<tr>";
            echo "<td>{$userId}</td>";
            echo "<td>" . htmlspecialchars($displayName) . "</td>";
            echo "<td>" . htmlspecialchars($oldUsername) . "</td>";
            echo "<td colspan='2' style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</td>";
            echo "</tr>";
            
            $errors++;
        }
    }
    
    echo "</table>";
    
    echo "<h2>Summary</h2>";
    echo "<ul>";
    echo "<li>Total users: " . count($users) . "</li>";
    echo "<li>Updated: {$updated}</li>";
    echo "<li>Skipped: {$skipped}</li>";
    echo "<li>Errors: {$errors}</li>";
    echo "</ul>";
    
    // Create lock file to prevent re-running
    file_put_contents($lock_file, date('Y-m-d H:i:s'));
    
    echo "<p style='color: green; font-weight: bold;'>✓ Username regeneration complete!</p>";
    echo "<p style='color: orange;'>For security, this script has been locked. Delete fix_usernames.lock and fix_usernames.php after verifying the results.</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
