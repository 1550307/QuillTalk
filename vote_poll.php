<?php
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => $_SERVER['HTTP_HOST'], 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/poll_auth.php';
require __DIR__ . '/includes/history_events.php';

header('Content-Type: application/json');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method'], 405);
}

$userId = qt_poll_require_user_id($pdo);
if (!$userId) {
    respond(['success' => false, 'error' => qt_poll_auth_error_message($pdo)], 401);
}

$input = qt_poll_json_input() ?? [];
$pollId = (int)($input['poll_id'] ?? 0);
$optionId = (int)($input['option_id'] ?? 0);

if (!$pollId || !$optionId) {
    respond(['success' => false, 'error' => 'Missing required fields'], 400);
}

try {
    $pdo->beginTransaction();

    // Check if poll exists and is not ended
    $stmt = $pdo->prepare("SELECT * FROM polls WHERE id = ?");
    $stmt->execute([$pollId]);
    $poll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$poll) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Poll not found'], 404);
    }

    $normalizedEndedAt = qt_poll_normalize_input_datetime($poll['ended_at'] ?? null);
    $normalizedEndDate = qt_poll_normalize_input_datetime($poll['end_date'] ?? null);

    if ($normalizedEndedAt !== null) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Poll has ended'], 400);
    }

    // Check if poll has expired by date
    if ($normalizedEndDate !== null && qt_poll_has_expired($normalizedEndDate)) {
        $normalizedEndedAt = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare("UPDATE polls SET ended_at = ? WHERE id = ?");
        $stmt->execute([$normalizedEndedAt, $pollId]);
        $pdo->commit();
        respond(['success' => false, 'error' => 'Poll has expired'], 400);
    }

    // Verify option belongs to poll
    $stmt = $pdo->prepare("SELECT 1 FROM poll_options WHERE id = ? AND poll_id = ?");
    $stmt->execute([$optionId, $pollId]);
    if (!$stmt->fetch()) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Invalid option'], 400);
    }

    // Check if user already voted
    $stmt = $pdo->prepare("SELECT id FROM poll_votes WHERE poll_id = ? AND user_id = ?");
    $stmt->execute([$pollId, $userId]);
    $existingVote = $stmt->fetch();

    if ($existingVote) {
        // Update existing vote
        $stmt = $pdo->prepare("UPDATE poll_votes SET option_id = ?, voted_at = NOW() WHERE id = ?");
        $stmt->execute([$optionId, $existingVote['id']]);
    } else {
        // Create new vote
        $stmt = $pdo->prepare("INSERT INTO poll_votes (poll_id, option_id, user_id, voted_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$pollId, $optionId, $userId]);
    }

    // Check if poll should end by response count
    if ($poll['end_responses']) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) as vote_count FROM poll_votes WHERE poll_id = ?");
        $stmt->execute([$pollId]);
        $voteCount = $stmt->fetch(PDO::FETCH_ASSOC)['vote_count'];
        
        if ($voteCount >= $poll['end_responses']) {
            $normalizedEndedAt = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare("UPDATE polls SET ended_at = ? WHERE id = ?");
            $stmt->execute([$normalizedEndedAt, $pollId]);
        }
    }

    $pdo->commit();

    $historyChatType = (int)($poll['group_id'] ?? 0) > 0 ? 'group' : 'direct';
    $historyChatId = $historyChatType === 'group'
        ? (int)($poll['group_id'] ?? 0)
        : (int)($poll['recipient_id'] ?? 0);
    $pollTitle = trim((string)($poll['title'] ?? 'Poll'));

    qt_log_history_event($pdo, [
        'actor_user_id' => $userId,
        'chat_type' => $historyChatType,
        'chat_id' => $historyChatId > 0 ? $historyChatId : null,
        'event_type' => 'poll_voted',
        'event_value' => $pollTitle !== '' ? $pollTitle : 'Poll',
    ]);

    respond(['success' => true]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Vote poll error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to vote'], 500);
}
