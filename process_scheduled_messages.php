<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/history_events.php';

// This file should be called by a cron job every minute
// Or can be polled by the frontend

header('Content-Type: application/json; charset=utf-8');

// Get all pending scheduled messages that are due
$stmt = $pdo->prepare("
    SELECT id, sender_id, recipient_id, message, scheduled_time
    FROM scheduled_messages
    WHERE status = 'pending'
      AND scheduled_time <= NOW()
    ORDER BY scheduled_time ASC
    LIMIT 50
");
$stmt->execute();
$scheduled = $stmt->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;
$errors = [];

foreach ($scheduled as $item) {
    try {
        $sender_id = (int)$item['sender_id'];
        $recipient_id = $item['recipient_id'];
        $message = $item['message'];
        $scheduled_id = (int)$item['id'];
        
        // Insert the actual message
        $insertMsg = $pdo->prepare("
            INSERT INTO messages (sender_id, recipient_id, message, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $insertMsg->execute([$sender_id, $recipient_id, $message]);
        $message_id = (int)$pdo->lastInsertId();
        
        // Update scheduled message status
        $updateScheduled = $pdo->prepare("
            UPDATE scheduled_messages
            SET status = 'sent', sent_message_id = ?
            WHERE id = ?
        ");
        $updateScheduled->execute([$message_id, $scheduled_id]);

        $historyChatTarget = qt_parse_chat_target((string)$recipient_id);
        $historyChatType = in_array((string)($historyChatTarget['type'] ?? ''), ['direct', 'group'], true)
            ? (string)$historyChatTarget['type']
            : null;
        $historyChatId = $historyChatType !== null ? (int)($historyChatTarget['id'] ?? 0) : 0;

        qt_log_history_event($pdo, [
            'actor_user_id' => $sender_id,
            'chat_type' => $historyChatType,
            'chat_id' => $historyChatId > 0 ? $historyChatId : null,
            'event_type' => 'scheduled_message_sent',
            'event_value' => qt_history_describe_message_body((string)$message),
        ]);
        
        $processed++;
    } catch (Exception $e) {
        $errors[] = [
            'scheduled_id' => $item['id'],
            'error' => $e->getMessage()
        ];
    }
}

echo json_encode([
    'success' => true,
    'processed' => $processed,
    'errors' => $errors
]);
