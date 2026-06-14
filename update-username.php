<?php
declare(strict_types=1);
if (isset($_COOKIE['PHPSESSID'])) {
    setcookie(
        'PHPSESSID',
        '',
        [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => 'quilltalk.org', // remove non-dot cookie
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'None'
        ]
    );
}
header('Content-Type: application/json');

session_start();
ob_start(); // prevent whitespace / BOM issues

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

// --- MUST have a logged-in user ---
if ($userId <= 0) {
    echo json_encode([
        'success' => false,
        'error'   => 'Not logged in'
    ]);
    exit;
}

// --- validate input ---
if (empty($_POST['newUsername'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'Missing display name'
    ]);
    exit;
}

$newDisplayName = trim($_POST['newUsername']);

if ($newDisplayName === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'Display name cannot be empty'
    ]);
    exit;
}

// --- update DB ---
try {
    $stmt = $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?");
    $stmt->execute([$newDisplayName, $userId]);

    // update session
    $_SESSION['display_name'] = $newDisplayName;

    echo json_encode([
        'success'      => true,
        'display_name' => $newDisplayName
    ]);
    exit;
} catch (Throwable $e) {

    echo json_encode([
        'success' => false,
        'error'   => 'Database error: ' . $e->getMessage()
    ]);
    exit;
}
