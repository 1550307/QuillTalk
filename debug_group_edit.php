<?php
declare(strict_types=1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_group_edit.log');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/includes/db.php';

echo "<h2>Group Message Edit Debug Tool</h2>";

// Test parameters - you can modify these
$testToken = $_GET['token'] ?? '';
$testMessageId = (int)($_GET['message_id'] ?? 0);
$testUserId = (int)($_GET['user_id'] ?? 0);

if ($testToken === '' && $testUserId > 0) {
    // Get a valid token for the user
    $stmt = $pdo->prepare("SELECT token FROM sessions WHERE user_id = ? LIMIT 1");
    $stmt->execute([$testUserId]);
    $testToken = $stmt->fetchColumn() ?: '';
}

echo "<form method='GET'>";
echo "Token: <input type='text' name='token' value='" . htmlspecialchars($testToken) . "' style='width:300px'><br>";
echo "Message ID: <input type='number' name='message_id' value='" . $testMessageId . "'><br>";
echo "User ID: <input type='number' name='user_id' value='" . $testUserId . "'><br>";
echo "<input type='submit' value='Debug'>";
echo "</form>";

if ($testToken && $testMessageId > 0) {
    echo "<h3>Debug Results:</h3>";
    
    // Validate session
    $sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $sessionStmt->execute([$testToken]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo "<p style='color:red'>❌ Invalid session token</p>";
        exit;
    }
    
    $userId = (int)$session['user_id'];
    echo "<p>✅ Valid session for user ID: $userId</p>";
    
    // Check if message exists in group_messages
    $check = $pdo->prepare("SELECT id, sender_id, message, group_id FROM group_messages WHERE id = ? LIMIT 1");
    $check->execute([$testMessageId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        echo "<p style='color:red'>❌ Message not found in group_messages table</p>";
        
        // Check other tables
        $directCheck = $pdo->prepare("SELECT id, sender_id, message FROM messages WHERE id = ? LIMIT 1");
        $directCheck->execute([$testMessageId]);
        $directRow = $directCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($directRow) {
            echo "<p style='color:orange'>⚠️ Message found in direct messages table instead</p>";
        } else {
            $aiCheck = $pdo->prepare("SELECT id, user_id as sender_id, message FROM ai_chat_messages WHERE id = ? LIMIT 1");
            $aiCheck->execute([$testMessageId]);
            $aiRow = $aiCheck->fetch(PDO::FETCH_ASSOC);
            
            if ($aiRow) {
                echo "<p style='color:orange'>⚠️ Message found in AI messages table instead</p>";
            } else {
                echo "<p style='color:red'>❌ Message not found in any table</p>";
            }
        }
        exit;
    }
    
    echo "<p>✅ Message found in group_messages table</p>";
    echo "<p>Message ID: {$row['id']}</p>";
    echo "<p>Sender ID: {$row['sender_id']}</p>";
    echo "<p>Group ID: {$row['group_id']}</p>";
    echo "<p>Message: " . htmlspecialchars(substr($row['message'], 0, 100)) . "</p>";
    
    $groupId = (int)$row['group_id'];
    $senderId = (int)$row['sender_id'];
    
    // Check if user is the sender
    if ($senderId === $userId) {
        echo "<p style='color:green'>✅ User is the message sender - can edit</p>";
    } else {
        echo "<p style='color:orange'>⚠️ User is NOT the message sender</p>";
        
        // Check if user is admin/owner in the group
        $adminStmt = $pdo->prepare("
            SELECT role 
            FROM chat_group_members 
            WHERE group_id = ? AND user_id = ? 
            LIMIT 1
        ");
        $adminStmt->execute([$groupId, $userId]);
        $memberData = $adminStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$memberData) {
            echo "<p style='color:red'>❌ User is not a member of this group</p>";
        } else {
            $role = $memberData['role'];
            echo "<p>User role in group: $role</p>";
            
            if (in_array($role, ['admin', 'owner'])) {
                echo "<p style='color:green'>✅ User is admin/owner - can edit any message</p>";
            } else {
                echo "<p style='color:red'>❌ User is regular member - cannot edit others' messages</p>";
            }
        }
    }
    
    // Show all group members for context
    echo "<h4>Group Members:</h4>";
    $membersStmt = $pdo->prepare("
        SELECT u.id, u.username, u.display_name, cgm.role
        FROM chat_group_members cgm
        JOIN users u ON cgm.user_id = u.id
        WHERE cgm.group_id = ?
        ORDER BY cgm.role DESC, u.display_name ASC
    ");
    $membersStmt->execute([$groupId]);
    $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr><th>User ID</th><th>Username</th><th>Display Name</th><th>Role</th></tr>";
    foreach ($members as $member) {
        $highlight = ($member['id'] == $userId) ? 'style="background-color: yellow"' : '';
        echo "<tr $highlight>";
        echo "<td>{$member['id']}</td>";
        echo "<td>{$member['username']}</td>";
        echo "<td>{$member['display_name']}</td>";
        echo "<td>{$member['role']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>