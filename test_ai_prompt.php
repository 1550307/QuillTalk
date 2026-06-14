<?php
/**
 * Test AI Prompt - See exactly what system prompt is sent to the AI
 * 
 * Usage: test_ai_prompt.php?token=YOUR_TOKEN&chat_key=CHAT_KEY&prompt=YOUR_QUESTION
 */

declare(strict_types=1);
require __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ''));
$chatKey = trim((string)($_GET['chat_key'] ?? ''));
$userPrompt = trim((string)($_GET['prompt'] ?? 'Who are the members of this group?'));

if ($token === '') {
    die("ERROR: Missing token parameter\nUsage: test_ai_prompt.php?token=YOUR_TOKEN&chat_key=CHAT_KEY&prompt=YOUR_QUESTION");
}

if ($chatKey === '') {
    die("ERROR: Missing chat_key parameter\nUsage: test_ai_prompt.php?token=YOUR_TOKEN&chat_key=CHAT_KEY&prompt=YOUR_QUESTION");
}

// Validate session
$sessionStmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("ERROR: Invalid session token");
}

$userId = (int)$session['user_id'];

echo "=== AI PROMPT TEST ===\n\n";
echo "User ID: {$userId}\n";
echo "Chat Key: {$chatKey}\n";
echo "User Prompt: {$userPrompt}\n\n";
echo str_repeat("=", 80) . "\n\n";

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
    $groupId = (int)substr($chatKey, 6);
    
    if ($groupId > 0) {
        try {
            $groupStmt = $pdo->prepare('
                SELECT name, description, created_at
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
                    
                    $chatContextInfo .= "\nExample queries you can answer:\n";
                    $chatContextInfo .= "- 'What is this group called?' → Answer: This group is called " . ($groupInfo['name'] ?? 'Unknown') . "\n";
                    $chatContextInfo .= "- 'Who are the members?' → Answer: List all members shown above\n";
                    $chatContextInfo .= "- 'Who is online?' → Answer: List members with 'Status: online'\n";
                    
                    $admins = array_filter($members, function($m) { return in_array($m['role'], ['owner', 'admin']); });
                    if ($admins) {
                        $adminNames = array_map(function($m) { return $m['display_name'] ?? $m['username']; }, $admins);
                        $chatContextInfo .= "- 'Who are the admins?' → Answer: " . implode(', ', $adminNames) . "\n";
                    }
                }
            }
        } catch (PDOException $e) {
            $chatContextInfo .= "\nERROR: " . $e->getMessage() . "\n";
        }
    }
} elseif (str_starts_with($chatKey, 'ai:')) {
    $chatContextInfo .= "\n\n=== AI CHAT CONTEXT ===\n";
    $chatContextInfo .= "This is a private AI chat with you.\n";
}

// Add current user info
if ($currentUserInfo) {
    $chatContextInfo .= "\n=== YOUR INFO ===\n";
    $chatContextInfo .= "Your Display Name: " . ($currentUserInfo['display_name'] ?? 'Unknown') . "\n";
    $chatContextInfo .= "Your Username: @" . ($currentUserInfo['username'] ?? 'unknown') . "\n";
    if (!empty($currentUserInfo['bio'])) {
        $chatContextInfo .= "Your Bio: " . $currentUserInfo['bio'] . "\n";
    }
    $chatContextInfo .= "Your Member Since: " . ($currentUserInfo['created_at'] ?? 'Unknown') . "\n";
    
    $chatContextInfo .= "\nExample queries you can answer:\n";
    $chatContextInfo .= "- 'What is my username?' → Answer: Your username is @" . ($currentUserInfo['username'] ?? 'unknown') . "\n";
    $chatContextInfo .= "- 'What is my display name?' → Answer: Your display name is " . ($currentUserInfo['display_name'] ?? 'Unknown') . "\n";
}

// Build the system prompt (same as ai_chat.php)
$systemPrompt = "You are QuillTalk AI, an AI assistant built into QuillTalk, a messaging platform. ";
$systemPrompt .= "You were created by a team of developers at QuillTalk. ";
$systemPrompt .= "Keep responses concise and friendly. ";

if ($chatContextInfo !== '') {
    $systemPrompt .= "\n\n=== CRITICAL: YOU HAVE FULL ACCESS TO THIS INFORMATION ===\n";
    $systemPrompt .= "The data below is LIVE, REAL-TIME information from the QuillTalk database. ";
    $systemPrompt .= "This is NOT hypothetical - this is ACTUAL data about the current chat, users, and group.\n\n";
    $systemPrompt .= "⚠️ MANDATORY RULES:\n";
    $systemPrompt .= "1. When asked about group name, members, roles, or online status → USE THE GROUP CHAT CONTEXT SECTION BELOW\n";
    $systemPrompt .= "2. When asked about your username, display name, or bio → USE THE YOUR INFO SECTION BELOW\n";
    $systemPrompt .= "3. When asked about other users → USE THE PRIVATE CHAT CONTEXT or GROUP CHAT CONTEXT SECTIONS BELOW\n";
    $systemPrompt .= "4. NEVER say 'I don't have access' or 'I don't know' when the answer is clearly provided below\n";
    $systemPrompt .= "5. If you see 'Group Members:' followed by a list, YOU CAN answer questions about those members\n";
    $systemPrompt .= "6. If you see 'Group Name:' followed by a name, YOU KNOW the group name\n";
    $systemPrompt .= "7. Read the 'Example queries you can answer:' section - those are questions you MUST be able to answer\n\n";
    $systemPrompt .= $chatContextInfo . "\n";
    $systemPrompt .= "=== END OF SYSTEM DATA ===\n\n";
    $systemPrompt .= "REMINDER: Everything above this line is REAL DATA you have access to. Use it to answer questions. ";
    $systemPrompt .= "Do not be uncertain or say you lack information when it's clearly provided above. ";
}

echo "SYSTEM PROMPT THAT WILL BE SENT TO AI:\n";
echo str_repeat("=", 80) . "\n";
echo $systemPrompt;
echo "\n" . str_repeat("=", 80) . "\n\n";

echo "USER PROMPT:\n";
echo str_repeat("=", 80) . "\n";
echo $userPrompt;
echo "\n" . str_repeat("=", 80) . "\n\n";

echo "ANALYSIS:\n";
echo str_repeat("=", 80) . "\n";
echo "System Prompt Length: " . strlen($systemPrompt) . " characters\n";
echo "Context Info Length: " . strlen($chatContextInfo) . " characters\n";

if (str_starts_with($chatKey, 'group:')) {
    if (strpos($systemPrompt, 'GROUP CHAT CONTEXT') !== false) {
        echo "✓ Group context is included in system prompt\n";
    } else {
        echo "✗ Group context is MISSING from system prompt\n";
    }
    
    if (strpos($systemPrompt, 'Group Members:') !== false) {
        echo "✓ Group members list is included\n";
    } else {
        echo "✗ Group members list is MISSING\n";
    }
    
    if (strpos($systemPrompt, 'Example queries you can answer:') !== false) {
        echo "✓ Example queries are included\n";
    } else {
        echo "✗ Example queries are MISSING\n";
    }
}

echo "\nThis is EXACTLY what the AI model will receive as its system prompt.\n";
echo "If the group information is shown above, the AI should be able to answer questions about it.\n";
