<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

session_start();
require __DIR__ . '/includes/db.php';

function resolveAuthenticatedUserId(PDO $pdo): int
{
    $token = trim((string)($_POST['token'] ?? ''));
    if ($token !== '') {
        $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $resolvedUserId = (int) ($stmt->fetchColumn() ?: 0);

        if ($resolvedUserId > 0) {
            $_SESSION['user_id'] = $resolvedUserId;
            return $resolvedUserId;
        }

        return 0;
    }

    if (!empty($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }

    return 0;
}

$userId = resolveAuthenticatedUserId($pdo);

if ($userId <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Not logged in'
    ]);
    exit;
}

$bio = trim((string)($_POST['bio'] ?? ''));
if (strlen($bio) > 400) {
    echo json_encode([
        'success' => false,
        'error' => 'Bio must be 400 characters or less'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE users SET bio = ? WHERE id = ?");
    $stmt->execute([$bio, $userId]);

    $_SESSION['bio'] = $bio;

    echo json_encode([
        'success' => true,
        'bio' => $bio
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
}
