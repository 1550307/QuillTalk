<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

ensure_message_metadata_schema($pdo);

/**
 * @return array{0: string[], 1: array<int, mixed>}
 */
function qt_build_search_filter_clauses(
    string $messageAlias,
    string $userAlias,
    string $query,
    string $filter,
    string $queryLike,
    string $filterLike,
    string $mentionLike
): array {
    $clauses = [];
    $params = [];

    if ($query !== '') {
        $clauses[] = "{$messageAlias}.message LIKE ?";
        $params[] = $queryLike;
    }

    switch ($filter) {
        case 'media':
            $clauses[] = "{$messageAlias}.message LIKE ?";
            $params[] = '__ATTACHMENT__:%';
            $clauses[] = "(
                {$messageAlias}.message LIKE ?
                OR {$messageAlias}.message LIKE ?
                OR {$messageAlias}.message LIKE ?
                OR {$messageAlias}.message LIKE ?
            )";
            $params[] = '%"kind":"image"%';
            $params[] = '%"kind":"video"%';
            $params[] = '%"mime":"image/%';
            $params[] = '%"mime":"video/%';
            break;

        case 'links':
            $clauses[] = "{$messageAlias}.message NOT LIKE ?";
            $params[] = '__ATTACHMENT__:%';
            $clauses[] = "{$messageAlias}.message NOT LIKE ?";
            $params[] = '__POLL__:%';
            $clauses[] = "{$messageAlias}.message NOT LIKE ?";
            $params[] = '__TABLE__:%';
            $clauses[] = "{$messageAlias}.message NOT LIKE ?";
            $params[] = '__GAME__:%';
            $clauses[] = "(
                {$messageAlias}.message LIKE ?
                OR {$messageAlias}.message LIKE ?
                OR {$messageAlias}.message LIKE ?
            )";
            $params[] = '%http://%';
            $params[] = '%https://%';
            $params[] = '%www.%';
            break;

        case 'polls':
            $clauses[] = "{$messageAlias}.message LIKE ?";
            $params[] = '__POLL__:%';
            break;

        case 'voice':
            $clauses[] = "{$messageAlias}.message LIKE ?";
            $params[] = '__ATTACHMENT__:%';
            $clauses[] = "(
                {$messageAlias}.message LIKE ?
                OR {$messageAlias}.message LIKE ?
            )";
            $params[] = '%"kind":"audio"%';
            $params[] = '%"mime":"audio/%';
            break;

        case 'from_user':
            $clauses[] = "(COALESCE(NULLIF({$userAlias}.display_name,''),{$userAlias}.username) LIKE ? OR {$userAlias}.username LIKE ?)";
            $params[] = $filterLike;
            $params[] = $filterLike;
            break;

        case 'mentions_user':
            $clauses[] = "{$messageAlias}.message LIKE ?";
            $params[] = $mentionLike;
            break;
    }

    return [$clauses, $params];
}

$token = trim((string)($_GET['token'] ?? ''));
$target = qt_parse_chat_target($_GET['with'] ?? '');
$query = trim((string)($_GET['q'] ?? ''));
$filter = trim((string)($_GET['filter'] ?? 'all'));
$filterValueRaw = trim((string)($_GET['filter_value'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = isset($_GET['per_page']) ? (int)($_GET['per_page']) : 20;

if ($perPage < 5) {
    $perPage = 5;
}
if ($perPage > 100) {
    $perPage = 100;
}

if (!in_array($filter, ['all', 'media', 'links', 'polls', 'voice', 'from_user', 'mentions_user'], true)) {
    $filter = 'all';
}

$filterValue = ltrim($filterValueRaw, "@ \t\n\r\0\x0B");

if (
    $token === ''
    || $target['type'] === 'unknown'
    || $target['id'] <= 0
    || ($query === '' && $filter === 'all')
    || (in_array($filter, ['from_user', 'mentions_user'], true) && $filterValue === '')
) {
    echo json_encode(['results' => [], 'total' => 0, 'pages' => 0]);
    exit;
}

$stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    echo json_encode(['results' => [], 'total' => 0, 'pages' => 0]);
    exit;
}

$userId = (int)$session['user_id'];
$offset = ($page - 1) * $perPage;
$queryLike = '%' . $query . '%';
$filterLike = '%' . $filterValue . '%';
$mentionLike = '%@' . $filterValue . '%';

if ($target['type'] === 'group') {
    if (!qt_user_can_access_group($pdo, $userId, $target['id'])) {
        echo json_encode(['results' => [], 'total' => 0, 'pages' => 0]);
        exit;
    }

    [$extraClauses, $extraParams] = qt_build_search_filter_clauses(
        'gm',
        'u',
        $query,
        $filter,
        $queryLike,
        $filterLike,
        $mentionLike
    );

    $whereParts = array_merge(['gm.group_id = ?'], $extraClauses);
    $whereSql = implode(' AND ', $whereParts);

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM group_messages gm
        JOIN users u ON u.id = gm.sender_id
        WHERE {$whereSql}
    ");
    $countStmt->execute(array_merge([$target['id']], $extraParams));
    $total = (int)$countStmt->fetchColumn();

    $resultStmt = $pdo->prepare("
        SELECT gm.id, gm.message, gm.created_at, gm.sender_id, gm.group_id,
               NULL AS recipient_id,
               CASE WHEN gm.sender_id = ? THEN 1 ELSE 0 END AS self,
               COALESCE(NULLIF(u.display_name,''),u.username) AS sender_display_name,
               COALESCE(NULLIF(u.profile_pic,''),'images/default-profile.png') AS sender_profile_pic,
               COALESCE(u.online,0) AS sender_online,
               gm.reply_to_id, gm.forward_from_user_id, gm.forward_from_display_name,
               rm.id AS reply_to_ref_id,
               COALESCE(NULLIF(ru.display_name,''),ru.username) AS reply_to_display_name,
               rm.message AS reply_to_message_body
        FROM group_messages gm
        JOIN users u ON u.id = gm.sender_id
        LEFT JOIN group_messages rm ON gm.reply_to_id = rm.id AND rm.group_id = gm.group_id
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE {$whereSql}
        ORDER BY gm.id DESC
        LIMIT ? OFFSET ?
    ");
    $resultStmt->execute(array_merge([$userId, $target['id']], $extraParams, [$perPage, $offset]));
} else {
    [$extraClauses, $extraParams] = qt_build_search_filter_clauses(
        'm',
        'u',
        $query,
        $filter,
        $queryLike,
        $filterLike,
        $mentionLike
    );

    $conversationParams = [$userId, $target['id'], $target['id'], $userId];
    $whereParts = array_merge(
        ['((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))'],
        $extraClauses
    );
    $whereSql = implode(' AND ', $whereParts);

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE {$whereSql}
    ");
    $countStmt->execute(array_merge($conversationParams, $extraParams));
    $total = (int)$countStmt->fetchColumn();

    $resultStmt = $pdo->prepare("
        SELECT m.id, m.message, m.created_at, m.sender_id, m.recipient_id,
               NULL AS group_id,
               CASE WHEN m.sender_id = ? THEN 1 ELSE 0 END AS self,
               COALESCE(NULLIF(u.display_name,''),u.username) AS sender_display_name,
               COALESCE(NULLIF(u.profile_pic,''),'images/default-profile.png') AS sender_profile_pic,
               COALESCE(u.online,0) AS sender_online,
               m.reply_to_id, m.forward_from_user_id, m.forward_from_display_name,
               rm.id AS reply_to_ref_id,
               COALESCE(NULLIF(ru.display_name,''),ru.username) AS reply_to_display_name,
               rm.message AS reply_to_message_body
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN messages rm ON m.reply_to_id = rm.id AND (
            (rm.sender_id = ? AND rm.recipient_id = ?) OR (rm.sender_id = ? AND rm.recipient_id = ?)
        )
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE {$whereSql}
        ORDER BY m.id DESC
        LIMIT ? OFFSET ?
    ");
    $resultStmt->execute(array_merge(
        [$userId],
        $conversationParams,
        $conversationParams,
        $extraParams,
        [$perPage, $offset]
    ));
}

$results = $resultStmt->fetchAll(PDO::FETCH_ASSOC);
$pages = $total > 0 ? (int)ceil($total / $perPage) : 0;

echo json_encode([
    'results' => $results,
    'total' => $total,
    'pages' => $pages,
    'page' => $page,
    'per_page' => $perPage,
], JSON_UNESCAPED_UNICODE);
