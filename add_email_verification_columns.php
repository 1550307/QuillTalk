<?php
/**
 * Migration script to add email verification columns
 * Run this once to add the necessary columns to the users table
 */

require_once __DIR__ . '/includes/db.php';

try {
    // Check if columns already exist
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verification_code'");
    $codeColumnExists = $stmt->rowCount() > 0;
    
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verification_expires'");
    $expiresColumnExists = $stmt->rowCount() > 0;
    
    if ($codeColumnExists && $expiresColumnExists) {
        echo "✓ Email verification columns already exist.\n";
        exit;
    }
    
    // Add email_verification_code column
    if (!$codeColumnExists) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verification_code VARCHAR(6) DEFAULT NULL");
        echo "✓ Added email_verification_code column\n";
    }
    
    // Add email_verification_expires column
    if (!$expiresColumnExists) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verification_expires DATETIME DEFAULT NULL");
        echo "✓ Added email_verification_expires column\n";
    }
    
    echo "\n✓ Migration completed successfully!\n";
    echo "You can now delete this file (add_email_verification_columns.php)\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
?>
