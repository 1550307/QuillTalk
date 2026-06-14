<?php
/**
 * Test AI Context - Debug Tool
 * 
 * This file helps verify that the AI context system is working correctly.
 * Access it with: test_ai_context.php?token=YOUR_TOKEN&chat_key=CHAT_KEY
 */

declare(strict_types=1);
require __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ''));
$chatKey = trim((string)($_GET['chat_key'] ?? ''));

if ($token === '') {
    die("ERROR: Missing token parameter\nUsage: test_ai_context.php?token=YOUR_TOKEN&chat_key=CHAT_KEY");
}

if ($chatKey === '') {
    die("ERROR: Missing chat_key parameter\nUsage: test_ai_context.php?token=YOUR_TOKEN&chat_key=CHAT_KEY\n\nExamples:\n- Private chat: chat_key=123 (user ID)\n- Group chat: chat_key=group:456\n- AI chat: chat_key=ai:789");
}

// Validate session
$sessionStmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("ERROR: Invalid session token");
}

$userId = (int)$session['user_id'];

echo "=== AI CONTEXT DEBUG TOOL ===\n\n";
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
    echo "Extracted Group ID: {$groupId}\n\n";
    
    if ($groupId > 0) {
        try {
            // Get group info
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
                $chatContextInfo .= "\n\n=== GROUP CHAT CONTEXT ===\n";
                $chatContextInfo .= "Group Name: " . ($groupInfo['name'] ?? 'Unknown') . "\n";
                if (!empty($groupInfo['description'])) {
                    $chatContextInfo .= "Group Description: " . $groupInfo['description'] . "\n";
                }
                $chatContextInfo .= "Group Created: " . ($groupInfo['created_at'] ?? 'Unknown') . "\n";
                
                // Get group members info
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
                }
            } else {
                echo "ERROR: Group not found or access denied\n";
            }
        } catch (PDOException $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    }
} elseif (str_starts_with($chatKey, 'ai:')) {
    echo "Chat Type: AI CHAT\n\n";
    
    // AI chat context - just current user info
    $chatContextInfo .= "\n\n=== AI CHAT CONTEXT ===\n";
    $chatContextInfo .= "This is a private AI chat with you.\n";
} else {
    echo "Chat Type: PRIVATE CHAT\n\n";
    
    // Private chat with another user
    $otherUserId = (int)$chatKey;
    if ($otherUserId > 0) {
        try {
            $otherUserStmt = $pdo->prepare('
                SELECT 
                    u.id,
                    u.username,
                    COALESCE(NULLIF(u.display_name, ""), u.username) AS display_name,
                    COALESCE(u.bio, "") AS bio,
                    u.created_at,
                    CASE 
                        WHEN s.user_id IS NOT NULL THEN 1 
                        ELSE 0 
                    END AS is_online,
                    pc.nickname_user1,
                    pc.nickname_user2
                FROM users u
                LEFT JOIN sessions s ON u.id = s.user_id AND s.expires_at > NOW()
                LEFT JOIN private_chats pc ON 
                    (pc.user1_id = ? AND pc.user2_id = ?) OR 
                    (pc.user1_id = ? AND pc.user2_id = ?)
                WHERE u.id = ?
                LIMIT 1
            ');
            $otherUserStmt->execute([$userId, $otherUserId, $otherUserId, $userId, $otherUserId]);
            $otherUserInfo = $otherUserStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($otherUserInfo) {
                $chatContextInfo .= "\n\n=== PRIVATE CHAT CONTEXT ===\n";
                $chatContextInfo .= "Other User: " . ($otherUserInfo['display_name'] ?? 'Unknown') . " (@" . ($otherUserInfo['username'] ?? 'unknown') . ")\n";
                if (!empty($otherUserInfo['bio'])) {
                    $chatContextInfo .= "Their Bio: " . $otherUserInfo['bio'] . "\n";
                }
                $chatContextInfo .= "Member Since: " . ($otherUserInfo['created_at'] ?? 'Unknown') . "\n";
                $chatContextInfo .= "Status: " . ($otherUserInfo['is_online'] ? 'online' : 'offline') . "\n";
                
                // Check for chat nicknames
                if (!empty($otherUserInfo['nickname_user1']) || !empty($otherUserInfo['nickname_user2'])) {
                    $yourNickname = ($userId < $otherUserId) ? $otherUserInfo['nickname_user1'] : $otherUserInfo['nickname_user2'];
                    $theirNickname = ($userId < $otherUserId) ? $otherUserInfo['nickname_user2'] : $otherUserInfo['nickname_user1'];
                    
                    if (!empty($yourNickname)) {
                        $chatContextInfo .= "Your Nickname in this chat: " . $yourNickname . "\n";
                    }
                    if (!empty($theirNickname)) {
                        $chatContextInfo .= "Their Nickname in this chat: " . $theirNickname . "\n";
                    }
                }
            } else {
                echo "ERROR: User not found\n";
            }
        } catch (PDOException $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    }
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
echo "\nIf you see your information above, the AI should be able to answer questions about it.\n";
echo "Check the ai_debug.log file for more detailed logging.\n";
