<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

function qt_delete_group_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    if (ob_get_length() > 0) {
        ob_clean();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($data)) {
    $data = $_POST ?: [];
}

$token = trim((string)($data['token'] ?? ''));
$groupId = (int)($data['group_id'] ?? 0);

if ($token === '' || $groupId <= 0) {
    qt_delete_group_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    qt_delete_group_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$viewerId = (int)($session['user_id'] ?? 0);
$viewerMember = qt_get_group_member_record($pdo, $groupId, $viewerId);
if (!$viewerMember || !qt_group_role_is_owner($viewerMember['role'] ?? '')) {
    qt_delete_group_respond(['success' => false, 'error' => 'Only the group owner can delete this group'], 403);
}

$iconStmt = $pdo->prepare("
    SELECT COALESCE(icon_path, '') AS icon_path
    FROM chat_groups
    WHERE id = ?
    LIMIT 1
");
$iconStmt->execute([$groupId]);
$groupRow = $iconStmt->fetch(PDO::FETCH_ASSOC);
if (!$groupRow) {
    qt_delete_group_respond(['success' => false, 'error' => 'Group chat not found'], 404);
}

$storedIconPath = trim((string)($groupRow['icon_path'] ?? ''));
$chatKey = qt_build_group_chat_key($groupId);

try {
    $pdo->beginTransaction();

    $pdo->prepare("
        DELETE gcm
        FROM group_call_members gcm
        INNER JOIN group_calls gc
            ON gc.id = gcm.call_id
        WHERE gc.group_id = ?
    ")->execute([$groupId]);

    $pdo->prepare("DELETE FROM group_calls WHERE group_id = ?")->execute([$groupId]);
    $pdo->prepare("DELETE FROM group_messages WHERE group_id = ?")->execute([$groupId]);
    $pdo->prepare("DELETE FROM chat_group_members WHERE group_id = ?")->execute([$groupId]);
    $pdo->prepare("DELETE FROM chat_groups WHERE id = ?")->execute([$groupId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[DELETE GROUP] ' . $e->getMessage());
    qt_delete_group_respond(['success' => false, 'error' => 'Unable to delete that group right now'], 500);
}

if ($storedIconPath !== '') {
    qt_delete_group_icon_file($storedIconPath);
}

qt_delete_group_respond([
    'success' => true,
    'chat_key' => $chatKey,
]);
