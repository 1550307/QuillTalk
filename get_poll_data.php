<?php
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => $_SERVER['HTTP_HOST'], 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/poll_auth.php';

header('Content-Type: application/json');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$userId = qt_poll_require_user_id($pdo);
if (!$userId) {
    respond(['success' => false, 'error' => qt_poll_auth_error_message($pdo)], 401);
}

$pollId = (int)($_GET['poll_id'] ?? 0);
if (!$pollId) {
    respond(['success' => false, 'error' => 'Missing poll_id'], 400);
}

try {
    // Get poll data
    $stmt = $pdo->prepare("SELECT * FROM polls WHERE id = ?");
    $stmt->execute([$pollId]);
    $poll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$poll) {
        respond(['success' => false, 'error' => 'Poll not found'], 404);
    }

    // Get poll options with vote counts
    $stmt = $pdo->prepare("
        SELECT 
            po.id,
            po.option_index,
            po.option_text,
            po.option_image,
            COUNT(DISTINCT pv.user_id) as vote_count
        FROM poll_options po
        LEFT JOIN poll_votes pv ON po.id = pv.option_id
        WHERE po.poll_id = ?
        GROUP BY po.id
        ORDER BY po.option_index
    ");
    $stmt->execute([$pollId]);
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total unique voters
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) as total FROM poll_votes WHERE poll_id = ?");
    $stmt->execute([$pollId]);
    $totalVotes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get user's vote if any
    $stmt = $pdo->prepare("SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ?");
    $stmt->execute([$pollId, $userId]);
    $userVote = $stmt->fetch(PDO::FETCH_ASSOC);

    $normalizedEndDate = qt_poll_normalize_input_datetime($poll['end_date'] ?? null);
    $normalizedEndedAt = qt_poll_normalize_input_datetime($poll['ended_at'] ?? null);

    // Check if poll should be auto-ended by date
    if ($normalizedEndedAt === null && $normalizedEndDate !== null && qt_poll_has_expired($normalizedEndDate)) {
        $normalizedEndedAt = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare("UPDATE polls SET ended_at = ? WHERE id = ?");
        $stmt->execute([$normalizedEndedAt, $pollId]);
    }

    respond([
        'success' => true,
        'poll' => [
            'id' => $poll['id'],
            'creator_id' => $poll['creator_id'],
            'title' => $poll['title'],
            'end_date' => qt_poll_datetime_to_iso8601($normalizedEndDate),
            'end_responses' => $poll['end_responses'],
            'ended_at' => qt_poll_datetime_to_iso8601($normalizedEndedAt),
            'created_at' => qt_poll_datetime_to_iso8601($poll['created_at']),
            'is_ended' => $normalizedEndedAt !== null
        ],
        'options' => $options,
        'total_votes' => $totalVotes,
        'user_vote' => $userVote ? $userVote['option_id'] : null
    ]);

} catch (Exception $e) {
    error_log('Get poll data error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to get poll data'], 500);
}
