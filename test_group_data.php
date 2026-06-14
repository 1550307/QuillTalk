<?php
/**
 * Test Group Data - Verify group and member data exists
 * 
 * Usage: test_group_data.php?group_id=123
 */

declare(strict_types=1);
require __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$groupId = (int)($_GET['group_id'] ?? 0);

if ($groupId <= 0) {
    die("ERROR: Missing or invalid group_id parameter\nUsage: test_group_data.php?group_id=123");
}

echo "=== GROUP DATA TEST ===\n\n";
echo "Testing Group ID: {$groupId}\n\n";

// Test 1: Check if group exists
echo "TEST 1: Group Exists?\n";
echo "-------------------\n";
$groupStmt = $pdo->prepare('SELECT id, name, description, created_at FROM chat_groups WHERE id = ? LIMIT 1');
$groupStmt->execute([$groupId]);
$group = $groupStmt->fetch(PDO::FETCH_ASSOC);

if ($group) {
    echo "✓ Group found!\n";
    echo "  Name: " . $group['name'] . "\n";
    echo "  Description: " . ($group['description'] ?: '(none)') . "\n";
    echo "  Created: " . $group['created_at'] . "\n\n";
} else {
    die("✗ Group NOT found in database!\n\nPossible issues:\n- Group ID doesn't exist\n- Group was deleted\n- Database connection issue\n");
}

// Test 2: Check group members
echo "TEST 2: Group Members\n";
echo "-------------------\n";
$membersStmt = $pdo->prepare('
    SELECT 
        u.id,
        u.username,
        COALESCE(NULLIF(u.display_name, ""), u.username) AS display_name,
        gm.role,
        gm_nick.nickname
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
    echo "✓ Found " . count($members) . " member(s):\n\n";
    foreach ($members as $member) {
        $nickname = $member['nickname'] ? " (nickname: {$member['nickname']})" : '';
        echo "  - {$member['display_name']} (@{$member['username']}){$nickname}\n";
        echo "    Role: {$member['role']}\n";
        echo "    User ID: {$member['id']}\n\n";
    }
} else {
    echo "✗ No members found!\n\n";
    echo "Possible issues:\n";
    echo "- Group has no members (shouldn't happen)\n";
    echo "- group_members table is empty\n";
    echo "- JOIN with users table failed\n\n";
}

// Test 3: Check online status
echo "TEST 3: Online Status\n";
echo "-------------------\n";
$onlineStmt = $pdo->prepare('
    SELECT 
        u.id,
        u.username,
        u.display_name,
        CASE 
            WHEN u.online = 1 AND u.last_seen_at >= (NOW() - INTERVAL 90 SECOND) THEN 1 
            ELSE 0 
        END AS is_online,
        u.last_seen_at
    FROM chat_group_members gm
    JOIN users u ON gm.user_id = u.id
    WHERE gm.group_id = ?
');
$onlineStmt->execute([$groupId]);
$onlineData = $onlineStmt->fetchAll(PDO::FETCH_ASSOC);

if ($onlineData) {
    $onlineCount = 0;
    foreach ($onlineData as $user) {
        $status = $user['is_online'] ? '🟢 ONLINE' : '⚫ OFFLINE';
        $expires = $user['expires_at'] ? " (session expires: {$user['expires_at']})" : '';
        echo "  {$status} - {$user['display_name']} (@{$user['username']}){$expires}\n";
        if ($user['is_online']) $onlineCount++;
    }
    echo "\nTotal online: {$onlineCount} / " . count($onlineData) . "\n\n";
} else {
    echo "✗ Could not check online status\n\n";
}

// Test 4: Simulate AI context generation
echo "TEST 4: AI Context Preview\n";
echo "-------------------\n";
$chatContextInfo = "\n=== GROUP CHAT CONTEXT ===\n";
$chatContextInfo .= "Group Name: " . $group['name'] . "\n";
if (!empty($group['description'])) {
    $chatContextInfo .= "Group Description: " . $group['description'] . "\n";
}
$chatContextInfo .= "Group Created: " . $group['created_at'] . "\n";

if ($members) {
    $chatContextInfo .= "\nGroup Members:\n";
    foreach ($members as $idx => $member) {
        $displayName = $member['display_name'];
        $nickname = !empty($member['nickname']) ? ' (nickname: ' . $member['nickname'] . ')' : '';
        $role = $member['role'];
        
        // Get online status
        $onlineUser = array_filter($onlineData, function($u) use ($member) {
            return $u['id'] == $member['id'];
        });
        $status = $onlineUser ? (reset($onlineUser)['is_online'] ? 'online' : 'offline') : 'unknown';
        
        $chatContextInfo .= "  - {$displayName} (@{$member['username']}){$nickname} - Role: {$role} - Status: {$status}\n";
    }
}

echo $chatContextInfo . "\n";

echo "\n=== SUMMARY ===\n";
echo "Group exists: " . ($group ? 'YES' : 'NO') . "\n";
echo "Member count: " . count($members) . "\n";
echo "Context length: " . strlen($chatContextInfo) . " characters\n";
echo "\nIf all tests passed, the AI should be able to answer questions about this group.\n";
echo "If not, check the issues noted above.\n";
