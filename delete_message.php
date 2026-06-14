<?php
// ================= HARD SAFETY =================
declare(strict_types=1);
ob_start();
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/push_debug.log');
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// ================= HEADERS =================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$qtDeleteMessageResponseSent = false;
register_shutdown_function(static function () use (&$qtDeleteMessageResponseSent): void {
    $lastError = error_get_last();
    if ($lastError === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)($lastError['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    $message = trim((string)($lastError['message'] ?? 'Fatal error'));
    $file = trim((string)($lastError['file'] ?? ''));
    $line = (int)($lastError['line'] ?? 0);
    error_log('[DELETE MESSAGE FATAL] ' . $message . ($file !== '' ? ' in ' . $file . ($line > 0 ? ':' . $line : '') : ''));

    if ($qtDeleteMessageResponseSent || headers_sent()) {
        return;
    }

    http_response_code(500);
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode([
        'success' => false,
        'error' => 'The delete message endpoint crashed before it could finish.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/history_events.php';

function respond(array $data, int $code = 200): void {
    global $qtDeleteMessageResponseSent;
    $qtDeleteMessageResponseSent = true;
    http_response_code($code);
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function qt_delete_message_log_history_event_safely(PDO $pdo, array $event): void
{
    try {
        qt_log_history_event($pdo, $event);
    } catch (Throwable $error) {
        error_log('[DELETE MESSAGE] history warning: ' . $error->getMessage());
    }
}

try {
    ensure_message_visibility_schema($pdo);
} catch (Throwable $e) {
    error_log('[DELETE MESSAGE] schema error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Schema initialization failed'], 500);
}

$token = $_POST['token'] ?? '';
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$scope = $_POST['scope'] ?? 'me';

// Enhanced debugging
error_log('[DELETE MESSAGE] ===== NEW REQUEST ===== ' . date('Y-m-d H:i:s'));
error_log('[DELETE MESSAGE] Raw POST data: ' . print_r($_POST, true));
error_log('[DELETE MESSAGE] Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'not_set'));
error_log('[DELETE MESSAGE] Request Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'not_set'));
error_log('[DELETE MESSAGE] token=' . substr($token, 0, 10) . '..., messageId=' . $messageId . ', scope=' . $scope);
error_log('[DELETE MESSAGE] token empty: ' . ($token === '' ? 'YES' : 'NO'));
error_log('[DELETE MESSAGE] messageId <= 0: ' . ($messageId <= 0 ? 'YES' : 'NO'));

if ($token === '' || $messageId <= 0) {
    error_log('[DELETE MESSAGE] FAILING due to missing parameters - token empty: ' . ($token === '' ? 'YES' : 'NO') . ', messageId: ' . $messageId);
    respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$userId = (int)$session['user_id'];

// Determine whether the message exists in direct messages
$msgStmt = $pdo->prepare("SELECT id, sender_id, recipient_id, message FROM messages WHERE id = ? LIMIT 1");
$msgStmt->execute([$messageId]);
$msg = $msgStmt->fetch(PDO::FETCH_ASSOC);
$messageType = '';

if ($msg) {
    $messageType = 'messages';
} else {
    $gStmt = $pdo->prepare("SELECT id, sender_id, group_id, message FROM group_messages WHERE id = ? LIMIT 1");
    $gStmt->execute([$messageId]);
    $gmsg = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($gmsg) {
        $messageType = 'group_messages';
        $msg = $gmsg;
    } else {
        // Check AI messages
        $aiStmt = $pdo->prepare("SELECT id, user_id, message FROM ai_chat_messages WHERE id = ? LIMIT 1");
        $aiStmt->execute([$messageId]);
        $aimsg = $aiStmt->fetch(PDO::FETCH_ASSOC);
        if ($aimsg) {
            $messageType = 'ai_chat_messages';
            $msg = $aimsg;
            // For AI messages, the user_id is the owner, not sender_id
            $msg['sender_id'] = $msg['user_id'];
        }
    }
}

if ($messageType === '') {
    error_log('[DELETE MESSAGE] Message not found in any table: messageId=' . $messageId);
    respond(['success' => false, 'error' => 'Message not found'], 404);
}

error_log('[DELETE MESSAGE] Message found in table: ' . $messageType);
error_log('[DELETE MESSAGE] Message data: ' . print_r($msg, true));

// Validate message structure and sender
if (!isset($msg['sender_id']) || $msg['sender_id'] === null) {
    error_log('[DELETE MESSAGE] message missing sender_id, msg: ' . json_encode($msg));
    respond(['success' => false, 'error' => 'Invalid message structure'], 400);
}

$senderIdInt = (int)$msg['sender_id'];
$historyChatType = null;
$historyChatId = null;
if ($messageType === 'messages') {
    $historyChatType = 'direct';
    $historyChatId = $senderIdInt === $userId
        ? (int)($msg['recipient_id'] ?? 0)
        : $senderIdInt;
} elseif ($messageType === 'group_messages') {
    $historyChatType = 'group';
    $historyChatId = (int)($msg['group_id'] ?? 0);
}

if ($scope === 'me') {
    try {
        error_log('[DELETE MESSAGE] Inserting visibility: user=' . $userId . ', type=' . $messageType . ', msg=' . $messageId);
        
        // First try to insert; if duplicate exists, it's fine
        $ins = $pdo->prepare("INSERT IGNORE INTO message_visibility (user_id, message_type, message_id, hidden_at) VALUES (?, ?, ?, NOW())");
        if (!$ins) {
            error_log('[DELETE MESSAGE] prepare failed: ' . print_r($pdo->errorInfo(), true));
            respond(['success' => false, 'error' => 'Prepare error'], 500);
        }
        try {
            $exec = $ins->execute([$userId, $messageType, $messageId]);
        } catch (Throwable $executeError) {
            error_log('[DELETE MESSAGE] execute failed, retrying schema bootstrap: ' . $executeError->getMessage());
            ensure_message_visibility_schema($pdo);
            $ins = $pdo->prepare("INSERT IGNORE INTO message_visibility (user_id, message_type, message_id, hidden_at) VALUES (?, ?, ?, NOW())");
            if (!$ins) {
                error_log('[DELETE MESSAGE] retry prepare failed: ' . print_r($pdo->errorInfo(), true));
                respond(['success' => false, 'error' => 'Prepare error'], 500);
            }
            $exec = $ins->execute([$userId, $messageType, $messageId]);
        }
        if ($exec === false) {
            error_log('[DELETE MESSAGE] execute failed: ' . print_r($ins->errorInfo(), true));
            respond(['success' => false, 'error' => 'Execute error'], 500);
        }

        qt_delete_message_log_history_event_safely($pdo, [
            'actor_user_id' => $userId,
            'chat_type' => $historyChatType,
            'chat_id' => $historyChatId > 0 ? $historyChatId : null,
            'event_type' => 'message_deleted_for_me',
            'event_value' => qt_history_describe_message_body((string)($msg['message'] ?? '')),
        ]);

        respond(['success' => true]);
    } catch (Throwable $e) {
        error_log('[DELETE MESSAGE] insert visibility error: ' . $e->getMessage());
        respond(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// scope == everyone
if ($scope === 'everyone') {
    $canDeleteForEveryone = false;
    
    // Check if user is the sender
    if ($senderIdInt === $userId) {
        $canDeleteForEveryone = true;
    }
    // If it's a group message, check if user is admin/owner
    elseif ($messageType === 'group_messages') {
        error_log('[DELETE MESSAGE] Checking group message permissions for user=' . $userId);
        
        // Get the group ID from the message
        $groupStmt = $pdo->prepare("SELECT group_id FROM group_messages WHERE id = ? LIMIT 1");
        $groupStmt->execute([$messageId]);
        $groupData = $groupStmt->fetch(PDO::FETCH_ASSOC);
        
        error_log('[DELETE MESSAGE] Group data: ' . print_r($groupData, true));
        
        if ($groupData) {
            $groupId = (int)$groupData['group_id'];
            error_log('[DELETE MESSAGE] Checking admin status for groupId=' . $groupId . ', userId=' . $userId);
            
            // Check if user is admin or owner in this group
            $adminStmt = $pdo->prepare("
                SELECT role 
                FROM chat_group_members 
                WHERE group_id = ? AND user_id = ? 
                LIMIT 1
            ");
            $adminStmt->execute([$groupId, $userId]);
            $memberData = $adminStmt->fetch(PDO::FETCH_ASSOC);
            
            error_log('[DELETE MESSAGE] Member data: ' . print_r($memberData, true));
            
            if ($memberData) {
                $role = $memberData['role'];
                error_log('[DELETE MESSAGE] User role in group: ' . $role);
                
                if (in_array($role, ['admin', 'owner'])) {
                    $canDeleteForEveryone = true;
                    error_log('[DELETE MESSAGE] ✓ Admin/Owner authorized to delete: user=' . $userId . ', role=' . $role);
                } else {
                    error_log('[DELETE MESSAGE] ✗ User is member but not admin/owner: role=' . $role);
                }
            } else {
                error_log('[DELETE MESSAGE] ✗ User not found in group members table');
            }
        } else {
            error_log('[DELETE MESSAGE] ✗ Could not find group_id for message');
        }
    }
    // If it's an AI message, check if user owns the AI chat
    elseif ($messageType === 'ai_chat_messages') {
        // For AI messages, user_id in the message table is the chat owner
        error_log('[DELETE MESSAGE] AI message authorization check: msg user_id=' . ($msg['user_id'] ?? 'null') . ', current user=' . $userId);
        error_log('[DELETE MESSAGE] AI message data: ' . print_r($msg, true));
        
        if (isset($msg['user_id']) && (int)$msg['user_id'] === $userId) {
            $canDeleteForEveryone = true;
            error_log('[DELETE MESSAGE] AI chat owner deleting message: user=' . $userId);
        } else {
            error_log('[DELETE MESSAGE] AI authorization failed: msg user_id=' . ($msg['user_id'] ?? 'null') . ' !== current user=' . $userId);
        }
    }
    
    if (!$canDeleteForEveryone) {
        error_log('[DELETE MESSAGE] ✗ NOT AUTHORIZED - Final check failed');
        error_log('[DELETE MESSAGE] Details: sender=' . $senderIdInt . ', user=' . $userId . ', messageType=' . $messageType . ', canDelete=' . ($canDeleteForEveryone ? 'true' : 'false'));
        respond(['success' => false, 'error' => 'Not authorized'], 403);
    }
    
    error_log('[DELETE MESSAGE] ✓ AUTHORIZED - Proceeding with deletion');

    try {
        error_log('[DELETE MESSAGE] Deleting for everyone: type=' . $messageType . ', msg=' . $messageId);
        if ($messageType === 'messages') {
            $del = $pdo->prepare("DELETE FROM messages WHERE id = ?");
            $delExec = $del->execute([$messageId]);
        } elseif ($messageType === 'group_messages') {
            $del = $pdo->prepare("DELETE FROM group_messages WHERE id = ?");
            $delExec = $del->execute([$messageId]);
        } else { // ai_chat_messages
            $del = $pdo->prepare("DELETE FROM ai_chat_messages WHERE id = ?");
            $delExec = $del->execute([$messageId]);
        }
        
        if (!$delExec) {
            error_log('[DELETE MESSAGE] delete execute failed: ' . print_r($del->errorInfo(), true));
            respond(['success' => false, 'error' => 'Delete error'], 500);
        }
        
        // cleanup any visibility rows
        try {
            $cleanup = $pdo->prepare("DELETE FROM message_visibility WHERE message_type = ? AND message_id = ?");
            if ($cleanup) {
                $cleanup->execute([$messageType, $messageId]);
            }
        } catch (Throwable $cleanupError) {
            error_log('[DELETE MESSAGE] cleanup warning: ' . $cleanupError->getMessage());
        }

        qt_delete_message_log_history_event_safely($pdo, [
            'actor_user_id' => $userId,
            'subject_user_id' => $senderIdInt !== $userId ? $senderIdInt : null,
            'chat_type' => $historyChatType,
            'chat_id' => $historyChatId > 0 ? $historyChatId : null,
            'event_type' => 'message_deleted_everyone',
            'event_value' => qt_history_describe_message_body((string)($msg['message'] ?? '')),
        ]);

        respond(['success' => true]);
    } catch (Throwable $e) {
        error_log('[DELETE MESSAGE] delete error: ' . $e->getMessage());
        respond(['success' => false, 'error' => 'Delete error: ' . $e->getMessage()], 500);
    }
}

respond(['success' => false, 'error' => 'Unknown scope'], 400);
