<?php
/**
 * Test AI Context V2 - Debug Tool (Cache-busted version)
 * 
 * Access it with: test_ai_context_v2.php?token=YOUR_TOKEN&chat_key=CHAT_KEY
 * 
 * UPDATED: Fixed table names to chat_groups and chat_group_members
 */

declare(strict_types=1);
require __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ''));
$chatKey = trim((string)($_GET['chat_key'] ?? ''));

if ($token === '') {
    die("ERROR: Missing token parameter\nUsage: test_ai_context_v2.php?token=YOUR_TOKEN&chat_key=CHAT_KEY");
}

if ($chatKey === '') {
    die("ERROR: Missing chat_key parameter\nUsage: test_ai_context_v2.php?token=YOUR_TOKEN&chat_key=CHAT_KEY\n\nExamples:\n- Private chat: chat_key=123 (user ID)\n- Group chat: chat_key=group:456\n- AI chat: chat_key=ai:789");
}

// Validate session
$sessionStmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("ERROR: Invalid session token");
}

$userId = (int)$session['user_id'];

echo "=== AI CONTEXT DEBUG TOOL V2 ===\n\n";
echo "Authenticated User ID: {$userId}\n";
echo "Chat Key: {$chatKey}\n\n";

// Get current user info
$userInfoStmt = $pdo->prepare('
    SELECT 
        id,
        username,
        COALESCE(NULLIF(display_name, ""), username) AS display_name,
        COALESCE(bio, "") AS bio,
        created_at,
        profile_pic
    FROM users 
    WHERE id = ? 
    LIMIT 1
');
$userInfoStmt->execute([$userId]);
$currentUserInfo = $userInfoStmt->fetch(PDO::FETCH_ASSOC);

// Build context information about the chat
$chatContextInfo = '';

// Determine chat type and gather relevant info
if (str_starts_with($chatKey, 'group:')) {
    echo "Chat Type: GROUP CHAT\n\n";
    
    // Group chat context
    $groupId = (int)substr($chatKey, 6);
    echo "Extracted Group ID: {$groupId}\n";
    echo "Querying table: chat_groups\n\n";
    
    if ($groupId > 0) {
        try {
            // Get group info - USING CORRECT TABLE NAME: chat_groups
            $groupStmt = $pdo->prepare('
                SELECT 
                    name,
                    description,
                    created_at
                FROM chat_groups 
                WHERE id = ? 
                LIMIT 1
            ');
            $groupStmt->execute([$groupId]);
            $groupInfo = $groupStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($groupInfo) {
                echo "✓ Group found: " . $groupInfo['name'] . "\n\n";
                
                $chatContextInfo .= "\n\n=== GROUP CHAT CONTEXT ===\n";
                $chatContextInfo .= "Group Name: " . ($groupInfo['name'] ?? 'Unknown') . "\n";
                if (!empty($groupInfo['description'])) {
                    $chatContextInfo .= "Group Description: " . $groupInfo['description'] . "\n";
                }
                $chatContextInfo .= "Group Created: " . ($groupInfo['created_at'] ?? 'Unknown') . "\n";
                
                // Get group members info - USING CORRECT TABLE NAME: chat_group_members
                echo "Querying table: chat_group_members\n";
                $membersStmt = $pdo->prepare('
                    SELECT 
                        u.id,
                        u.username,
                        COALESCE(NULLIF(u.display_name, ""), u.username) AS display_name,
                        COALESCE(u.bio, "") AS bio,
                        gm.role,
                        gm_nick.nickname,
                        CASE 
                            WHEN u.online = 1 AND u.last_seen_at >= (NOW() - INTERVAL 90 SECOND) THEN 1 
                            ELSE 0 
                        END AS is_online
                    FROM chat_group_members gm
                    JOIN users u ON gm.user_id = u.id
                    LEFT JOIN chat_user_nicknames gm_nick 
                        ON gm_nick.user_id = u.id 
                        AND gm_nick.chat_type = "group" 
                        AND gm_nick.chat_id = ?
                    WHERE gm.group_id = ?
                    ORDER BY gm.role DESC, u.display_name ASC
                ');
                $membersStmt->execute([$groupId, $groupId]);
                $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "✓ Found " . count($members) . " member(s)\n\n";
                
                if ($members) {
                    $chatContextInfo .= "\nGroup Members:\n";
                    foreach ($members as $member) {
                        $displayName = $member['display_name'] ?? $member['username'];
                        $nickname = !empty($member['nickname']) ? ' (nickname: ' . $member['nickname'] . ')' : '';
                        $role = $member['role'] ?? 'member';
                        $status = $member['is_online'] ? 'online' : 'offline';
                        $bio = !empty($member['bio']) ? ' - Bio: ' . $member['bio'] : '';
                        
                        $chatContextInfo .= "  - {$displayName} (@{$member['username']}){$nickname} - Role: {$role} - Status: {$status}{$bio}\n";
                    }
                    
                    // Add example queries
                    $chatContextInfo .= "\nExample queries you can answer:\n";
                    $chatContextInfo .= "- 'What is this group called?' → Answer: This group is called " . ($groupInfo['name'] ?? 'Unknown') . "\n";
                    $chatContextInfo .= "- 'Who are the members?' → Answer: List all members shown above\n";
                    $chatContextInfo .= "- 'Who is online?' → Answer: List members with 'Status: online'\n";
                    
                    // Find admins/owners
                    $admins = array_filter($members, function($m) { return in_array($m['role'], ['owner', 'admin']); });
                    if ($admins) {
                        $adminNames = array_map(function($m) { return $m['display_name'] ?? $m['username']; }, $admins);
                        $chatContextInfo .= "- 'Who are the admins?' → Answer: " . implode(', ', $adminNames) . "\n";
                    }
                }
            } else {
                echo "✗ Group not found in database\n\n";
            }
        } catch (PDOException $e) {
            echo "✗ DATABASE ERROR: " . $e->getMessage() . "\n\n";
            echo "Error Code: " . $e->getCode() . "\n";
            if ($e->getCode() == '42S02') {
                echo "\nThis error means the table doesn't exist.\n";
                echo "Expected table: chat_groups\n";
                echo "Please verify your database schema.\n";
            }
        }
    }
} elseif (str_starts_with($chatKey, 'ai:')) {
    echo "Chat Type: AI CHAT\n\n";
    
    // AI chat context - just current user info
    $chatContextInfo .= "\n\n=== AI CHAT CONTEXT ===\n";
    $chatContextInfo .= "This is a private AI chat with you.\n";
}

// Add current user info to context
if ($currentUserInfo) {
    $chatContextInfo .= "\n=== YOUR INFO ===\n";
    $chatContextInfo .= "Your Display Name: " . ($currentUserInfo['display_name'] ?? 'Unknown') . "\n";
    $chatContextInfo .= "Your Username: @" . ($currentUserInfo['username'] ?? 'unknown') . "\n";
    if (!empty($currentUserInfo['bio'])) {
        $chatContextInfo .= "Your Bio: " . $currentUserInfo['bio'] . "\n";
    }
    $chatContextInfo .= "Your Member Since: " . ($currentUserInfo['created_at'] ?? 'Unknown') . "\n";
    
    // Add example queries to help AI understand
    $chatContextInfo .= "\nExample queries you can answer:\n";
    $chatContextInfo .= "- 'What is my username?' → Answer: Your username is @" . ($currentUserInfo['username'] ?? 'unknown') . "\n";
    $chatContextInfo .= "- 'What is my display name?' → Answer: Your display name is " . ($currentUserInfo['display_name'] ?? 'Unknown') . "\n";
    if (!empty($currentUserInfo['bio'])) {
        $chatContextInfo .= "- 'What is my bio?' → Answer: Your bio is: " . $currentUserInfo['bio'] . "\n";
    }
    $chatContextInfo .= "- 'When did I join?' → Answer: You joined on " . ($currentUserInfo['created_at'] ?? 'Unknown') . "\n";
}

echo "=== CONTEXT THAT WILL BE SENT TO AI ===\n";
echo $chatContextInfo;
echo "\n\n=== END OF CONTEXT ===\n\n";

echo "Context Length: " . strlen($chatContextInfo) . " characters\n";
echo "\nIf you see group information above, the AI should be able to answer questions about it.\n";
echo "Check the ai_debug.log file for more detailed logging.\n";
