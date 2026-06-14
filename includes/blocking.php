<?php
declare(strict_types=1);

function qt_get_block_relationship(PDO $pdo, int $viewerId, int $otherUserId): array
{
    if ($viewerId <= 0 || $otherUserId <= 0 || $viewerId === $otherUserId) {
        return [
            'viewer_has_blocked' => false,
            'blocked_viewer' => false,
            'either_blocked' => false,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
            MAX(CASE WHEN blocker_id = :viewer AND blocked_id = :other THEN 1 ELSE 0 END) AS viewer_has_blocked,
            MAX(CASE WHEN blocker_id = :other2 AND blocked_id = :viewer2 THEN 1 ELSE 0 END) AS blocked_viewer
        FROM user_blocks
        WHERE (blocker_id = :viewer3 AND blocked_id = :other3)
           OR (blocker_id = :other4 AND blocked_id = :viewer4)
    ");
    $stmt->execute([
        ':viewer' => $viewerId,
        ':other' => $otherUserId,
        ':other2' => $otherUserId,
        ':viewer2' => $viewerId,
        ':viewer3' => $viewerId,
        ':other3' => $otherUserId,
        ':other4' => $otherUserId,
        ':viewer4' => $viewerId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $viewerHasBlocked = !empty($row['viewer_has_blocked']);
    $blockedViewer = !empty($row['blocked_viewer']);

    return [
        'viewer_has_blocked' => $viewerHasBlocked,
        'blocked_viewer' => $blockedViewer,
        'either_blocked' => ($viewerHasBlocked || $blockedViewer),
    ];
}

function qt_has_block_between(PDO $pdo, int $userA, int $userB): bool
{
    return qt_get_block_relationship($pdo, $userA, $userB)['either_blocked'];
}

function qt_viewer_has_blocked(PDO $pdo, int $viewerId, int $otherUserId): bool
{
    return qt_get_block_relationship($pdo, $viewerId, $otherUserId)['viewer_has_blocked'];
}

function qt_get_blocked_user_ids(PDO $pdo, int $viewerId): array
{
    if ($viewerId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT blocked_id
        FROM user_blocks
        WHERE blocker_id = ?
    ");
    $stmt->execute([$viewerId]);

    return array_map(
        static fn($value): int => (int)$value,
        $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
    );
}

