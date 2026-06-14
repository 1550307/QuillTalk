<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

const QT_MAX_GROUP_SETTINGS_ICON_SIZE = 8 * 1024 * 1024;

function qt_group_settings_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    if (ob_get_length() > 0) {
        ob_clean();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function qt_sanitize_group_settings_file_name(string $name): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name));
    $safe = trim((string)$safe, '._-');
    return $safe !== '' ? substr($safe, 0, 120) : 'group';
}

$token = trim((string)($_POST['token'] ?? ''));
$groupId = (int)($_POST['group_id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$sendPermission = trim((string)($_POST['send_permission'] ?? ''));
$addMembersPermission = trim((string)($_POST['add_members_permission'] ?? ''));
$clearIcon = (($_POST['clear_icon'] ?? '0') === '1');
$iconFile = $_FILES['icon'] ?? null;

if ($token === '' || $groupId <= 0) {
    qt_group_settings_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    qt_group_settings_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$viewerId = (int)($session['user_id'] ?? 0);
$details = qt_fetch_group_details($pdo, $viewerId, $groupId);
if (!$details) {
    qt_group_settings_respond(['success' => false, 'error' => 'Group chat not found'], 404);
}

$group = $details['group'];
$viewerRole = (string)($group['viewer_role'] ?? QT_GROUP_ROLE_MEMBER);
$viewerCanManage = qt_group_role_can_manage($viewerRole);
$viewerIsOwner = qt_group_role_is_owner($viewerRole);
if (!$viewerCanManage) {
    qt_group_settings_respond(['success' => false, 'error' => 'You cannot manage this group chat'], 403);
}

$iconStmt = $pdo->prepare("
    SELECT COALESCE(icon_path, '') AS icon_path
    FROM chat_groups
    WHERE id = ?
    LIMIT 1
");
$iconStmt->execute([$groupId]);
$storedGroupRow = $iconStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$existingIconPath = trim((string)($storedGroupRow['icon_path'] ?? ''));

$updateFields = [];
$params = [];
$newIconPath = '';
$shouldDeleteExistingIcon = false;

if ($name !== '') {
    $updateFields[] = 'name = ?';
    $params[] = function_exists('mb_substr') ? mb_substr($name, 0, 255) : substr($name, 0, 255);
}

if (array_key_exists('description', $_POST)) {
    $updateFields[] = 'description = ?';
    $params[] = function_exists('mb_substr') ? mb_substr($description, 0, 1000) : substr($description, 0, 1000);
}

if ($viewerIsOwner && array_key_exists('send_permission', $_POST)) {
    $updateFields[] = 'send_permission = ?';
    $params[] = qt_group_normalize_send_permission($sendPermission);
}

if ($viewerIsOwner && array_key_exists('add_members_permission', $_POST)) {
    $updateFields[] = 'add_members_permission = ?';
    $params[] = qt_group_normalize_add_permission($addMembersPermission);
}

if ($clearIcon && !(is_array($iconFile) && (($iconFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE))) {
    $updateFields[] = 'icon_path = NULL';
    $shouldDeleteExistingIcon = true;
}

if (is_array($iconFile) && (($iconFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
    if (($iconFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        qt_group_settings_respond(['success' => false, 'error' => 'Group icon upload failed'], 400);
    }

    $fileSize = (int)($iconFile['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > QT_MAX_GROUP_SETTINGS_ICON_SIZE) {
        qt_group_settings_respond(['success' => false, 'error' => 'Group icon is too large'], 400);
    }

    $originalName = qt_sanitize_group_settings_file_name((string)($iconFile['name'] ?? 'group'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        qt_group_settings_respond(['success' => false, 'error' => 'Unsupported group icon type'], 400);
    }

    $tmpPath = (string)($iconFile['tmp_name'] ?? '');
    $mime = '';
    if (class_exists('finfo') && $tmpPath !== '') {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpPath);
    }
    if ($mime !== '' && !str_starts_with($mime, 'image/')) {
        qt_group_settings_respond(['success' => false, 'error' => 'Unsupported group icon type'], 400);
    }

    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'groups';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        qt_group_settings_respond(['success' => false, 'error' => 'Could not save group icon'], 500);
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $storedAbsolutePath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($tmpPath, $storedAbsolutePath)) {
        qt_group_settings_respond(['success' => false, 'error' => 'Could not save group icon'], 500);
    }

    $newIconPath = 'uploads/groups/' . $storedName;
    $updateFields[] = 'icon_path = ?';
    $params[] = $newIconPath;
    $shouldDeleteExistingIcon = true;
}

if (!$updateFields) {
    qt_group_settings_respond([
        'success' => true,
        'group' => $group,
        'members' => $details['members'],
    ]);
}

$params[] = $groupId;
try {
    $stmt = $pdo->prepare("
        UPDATE chat_groups
        SET " . implode(', ', $updateFields) . ",
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute($params);

    $updatedDetails = qt_fetch_group_details($pdo, $viewerId, $groupId);
    if (!$updatedDetails) {
        throw new RuntimeException('Unable to load updated group settings.');
    }
} catch (Throwable $e) {
    if ($newIconPath !== '') {
        qt_delete_group_icon_file($newIconPath);
    }

    error_log('[UPDATE GROUP SETTINGS] ' . $e->getMessage());
    qt_group_settings_respond(['success' => false, 'error' => 'Unable to save group settings right now.'], 500);
}

if ($shouldDeleteExistingIcon && $existingIconPath !== '' && $existingIconPath !== $newIconPath) {
    qt_delete_group_icon_file($existingIconPath);
}

qt_group_settings_respond([
    'success' => true,
    'group' => $updatedDetails['group'],
    'members' => $updatedDetails['members'],
]);
