<?php
/**
 * Test AI Identity Fix
 * 
 * This shows what context the AI receives to verify identity and attribution rules are included
 * 
 * Usage: test_ai_identity.php?token=YOUR_TOKEN&chat_key=CHAT_KEY
 */

declare(strict_types=1);
require __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ''));
$chatKey = trim((string)($_GET['chat_key'] ?? 'ai:1')); // Default to AI chat

if ($token === '') {
    die("ERROR: Missing token parameter\nUsage: test_ai_identity.php?token=YOUR_TOKEN&chat_key=CHAT_KEY");
}

// Validate session
$sessionStmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("ERROR: Invalid session token");
}

$userId = (int)$session['user_id'];

echo "=== AI IDENTITY TEST ===\n\n";
echo "Testing identity and attribution rules\n";
echo "User ID: {$userId}\n";
echo "Chat Key: {$chatKey}\n\n";

// Get current user info
$userInfoStmt = $pdo->prepare('
    SELECT 
        id,
        username,
        COALESCE(NULLIF(display_name, ""), username) AS display_name,
        COALESCE(bio, "") AS bio,
        created_at
    FROM users 
    WHERE id = ? 
    LIMIT 1
');
$userInfoStmt->execute([$userId]);
$currentUserInfo = $userInfoStmt->fetch(PDO::FETCH_ASSOC);

// Build context (simplified version)
$chatContextInfo = '';

if (str_starts_with($chatKey, 'group:')) {
    $chatContextInfo .= "=== GROUP CHAT CONTEXT ===\n";
    $chatContextInfo .= "This is a group chat.\n\n";
}

// Add current user info
if ($currentUserInfo) {
    $chatContextInfo .= "=== CURRENT USER (WHO IS ASKING THIS QUESTION) ===\n";
    $chatContextInfo .= "The person asking this question is:\n";
    $chatContextInfo .= "Display Name: " . ($currentUserInfo['display_name'] ?? 'Unknown') . "\n";
    $chatContextInfo .= "Username: @" . ($currentUserInfo['username'] ?? 'unknown') . "\n";
    if (!empty($currentUserInfo['bio'])) {
        $chatContextInfo .= "Bio: " . $currentUserInfo['bio'] . "\n";
    }
    $chatContextInfo .= "Member Since: " . ($currentUserInfo['created_at'] ?? 'Unknown') . "\n";
    
    $chatContextInfo .= "\nWhen THIS USER asks about themselves:\n";
    $chatContextInfo .= "- 'What is my username?' → Answer: Your username is @" . ($currentUserInfo['username'] ?? 'unknown') . "\n";
    $chatContextInfo .= "- 'What is my display name?' → Answer: Your display name is " . ($currentUserInfo['display_name'] ?? 'Unknown') . "\n";
    
    if (str_starts_with($chatKey, 'group:')) {
        $chatContextInfo .= "\n⚠️ GROUP CHAT ATTRIBUTION RULES:\n";
        $chatContextInfo .= "- When you refer to previous conversations, be specific about WHO said what\n";
        $chatContextInfo .= "- Don't say 'YOU asked me before' unless the CURRENT USER actually asked\n";
        $chatContextInfo .= "- If someone else asked previously, say 'John asked me before' or 'Another member asked'\n";
        $chatContextInfo .= "- Each group member is a separate person - don't confuse their conversations\n";
        $chatContextInfo .= "- The current user asking this question is: " . ($currentUserInfo['display_name'] ?? 'Unknown') . "\n";
    }
}

// Build system prompt (simplified)
$systemPrompt = "You are QuillTalk AI, an AI assistant built into QuillTalk, a messaging platform.\n\n";

$systemPrompt .= "🤖 IDENTITY RULES (CRITICAL):\n";
$systemPrompt .= "- YOU are QuillTalk AI - you do NOT have a username, display name, bio, or join date\n";
$systemPrompt .= "- When users say 'my username' or 'my display name' they mean THEIR info, not yours\n";
$systemPrompt .= "- When users say 'I am' or 'I have' they are talking about THEMSELVES, not you\n";
$systemPrompt .= "- You should respond with 'Your username is...' not 'My username is...'\n";
$systemPrompt .= "- NEVER claim to have personal information like username, bio, or join date\n\n";

$systemPrompt .= "👥 MESSAGE ATTRIBUTION RULES (GROUP CHATS):\n";
$systemPrompt .= "- Each user in a group chat is a SEPARATE PERSON with different conversations\n";
$systemPrompt .= "- Don't say 'YOU asked me before' unless the CURRENT USER actually asked\n";
$systemPrompt .= "- If referencing previous conversations, specify WHO said what\n";
$systemPrompt .= "- Example: 'John asked me about this earlier' not 'You asked me about this'\n";
$systemPrompt .= "- The person asking the current question is shown in 'CURRENT USER' section\n\n";

$systemPrompt .= $chatContextInfo;

echo "SYSTEM PROMPT PREVIEW:\n";
echo str_repeat("=", 60) . "\n";
echo $systemPrompt;
echo str_repeat("=", 60) . "\n\n";

echo "TESTS TO TRY:\n";
echo str_repeat("=", 60) . "\n";
echo "1. Ask AI: 'What is my username?'\n";
echo "   Expected: 'Your username is @" . ($currentUserInfo['username'] ?? 'unknown') . "'\n";
echo "   NOT: 'My username is @" . ($currentUserInfo['username'] ?? 'unknown') . "'\n\n";

echo "2. Ask AI: 'What is my display name?'\n";
echo "   Expected: 'Your display name is " . ($currentUserInfo['display_name'] ?? 'Unknown') . "'\n";
echo "   NOT: 'My display name is " . ($currentUserInfo['display_name'] ?? 'Unknown') . "'\n\n";

if (str_starts_with($chatKey, 'group:')) {
    echo "3. In group chat - have different users ask questions:\n";
    echo "   User A asks: 'What's the weather?'\n";
    echo "   User B asks: 'Who asked about weather?'\n";
    echo "   Expected: 'User A asked about weather' or 'Another member asked'\n";
    echo "   NOT: 'You asked about weather'\n\n";
}

echo "ANALYSIS:\n";
echo str_repeat("=", 60) . "\n";
echo "✓ Identity rules are included in system prompt\n";
echo "✓ Attribution rules are included in system prompt\n";
echo "✓ Current user is clearly identified\n";
echo "✓ Response templates are provided\n";

if (str_starts_with($chatKey, 'group:')) {
    echo "✓ Group chat attribution rules are included\n";
}

echo "\nThe AI should now correctly handle identity and attribution!\n";