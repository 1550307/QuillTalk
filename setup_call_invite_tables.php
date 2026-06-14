<?php
// This script creates the necessary database tables for call invites
// Run this once to set up the tables

require_once __DIR__ . '/includes/db.php';

try {
    // Read and execute the SQL file
    $sql = file_get_contents(__DIR__ . '/create_call_invite_tables.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "✓ Call invite tables created successfully!\n";
    echo "✓ call_invites table created\n";
    echo "✓ call_invite_rejections table created\n";
    echo "\nYou can now use the call invite feature.\n";
    echo "\nNote: Foreign key constraints were not added to avoid compatibility issues.\n";
    echo "If you want to add them later for referential integrity, run:\n";
    echo "mysql -u username -p database < add_call_invite_foreign_keys.sql\n";
    
} catch (Exception $e) {
    echo "✗ Error creating tables: " . $e->getMessage() . "\n";
    echo "\nTroubleshooting:\n";
    echo "1. Make sure your database connection is working\n";
    echo "2. Check that you have CREATE TABLE permissions\n";
    echo "3. Verify the users table exists if you're adding foreign keys\n";
    echo "4. Try running the SQL manually in phpMyAdmin or MySQL client\n";
    exit(1);
}
