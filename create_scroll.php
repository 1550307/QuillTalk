<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/scrolls.php';

const QT_SCROLL_PREFERRED_MAX_BYTES = 125829120; // 120 MB
const QT_SCROLL_TITLE_MAX_CHARS = 160;

$maxUploadBytes = qt_scrolls_max_upload_bytes(QT_SCROLL_PREFERRED_MAX_BYTES);
$maxUploadLabel = qt_scrolls_format_bytes($maxUploadBytes);
$contentLength = max(0, (int)($_SERVER['CONTENT_LENGTH'] ?? 0));
if ($maxUploadBytes > 0 && $contentLength > $maxUploadBytes) {
    qt_scrolls_respond([
        'success' => false,
        'error' => 'Please keep Scroll videos under ' . $maxUploadLabel . '.',
    ], 413);
}

$token = trim((string)($_POST['token'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$caption = trim((string)($_POST['caption'] ?? ''));

if ($title !== '') {
    $title = preg_replace('/\s+/u', ' ', $title) ?: $title;
    if (function_exists('mb_substr')) {
        $title = trim(mb_substr($title, 0, QT_SCROLL_TITLE_MAX_CHARS));
    } else {
        $title = trim(substr($title, 0, QT_SCROLL_TITLE_MAX_CHARS));
    }
}

if ($token === '') {
    qt_scrolls_respond(['success' => false, 'error' => 'Missing token'], 400);
}

$userId = qt_scrolls_resolve_user_id($pdo, $token);
if ($userId <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

if (!qt_scrolls_table_exists($pdo, 'scrolls')) {
    qt_scrolls_respond(['success' => false, 'error' => 'Scrolls storage is not ready right now.'], 503);
}

if (!isset($_FILES['video_file']) || !is_array($_FILES['video_file'])) {
    qt_scrolls_respond(['success' => false, 'error' => 'Choose a video before posting your Scroll.'], 400);
}

$videoFile = $_FILES['video_file'];
$uploadError = (int)($videoFile['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    error_log('[create_scroll] upload error code=' . $uploadError);
    switch ($uploadError) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            qt_scrolls_respond(['success' => false, 'error' => 'Please keep Scroll videos under ' . $maxUploadLabel . '.'], 413);
            break;
        case UPLOAD_ERR_PARTIAL:
            qt_scrolls_respond(['success' => false, 'error' => 'That upload was interrupted before it finished.'], 400);
            break;
        case UPLOAD_ERR_NO_FILE:
            qt_scrolls_respond(['success' => false, 'error' => 'Choose a video before posting your Scroll.'], 400);
            break;
        default:
            qt_scrolls_respond(['success' => false, 'error' => 'Could not upload that video right now.'], 400);
            break;
    }
}

$fileSize = max(0, (int)($videoFile['size'] ?? 0));
if ($fileSize <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'The selected video is empty.'], 400);
}
if ($maxUploadBytes > 0 && $fileSize > $maxUploadBytes) {
    qt_scrolls_respond(['success' => false, 'error' => 'Please keep Scroll videos under ' . $maxUploadLabel . '.'], 413);
}

$originalName = trim((string)($videoFile['name'] ?? ''));
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowedExtensions = ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v'];
$allowedMimeMap = [
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'video/ogg' => 'ogv',
    'video/quicktime' => 'mov',
    'video/x-m4v' => 'm4v',
];

$detectedMime = '';
if (class_exists('finfo')) {
    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = trim((string)$finfo->file((string)($videoFile['tmp_name'] ?? '')));
    } catch (Throwable $e) {
        $detectedMime = '';
    }
}

if (!in_array($extension, $allowedExtensions, true)) {
    $extension = $allowedMimeMap[$detectedMime] ?? '';
}
if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
    qt_scrolls_respond(['success' => false, 'error' => 'Please upload a supported video file.'], 400);
}

if ($detectedMime !== '' && stripos($detectedMime, 'video/') !== 0 && !isset($allowedMimeMap[$detectedMime])) {
    qt_scrolls_respond(['success' => false, 'error' => 'That file does not look like a video.'], 400);
}

$relativeDir = 'uploads/scrolls/' . date('Y') . '/' . date('m');
$absoluteDir = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
    qt_scrolls_respond(['success' => false, 'error' => 'Could not prepare storage for that Scroll.'], 500);
}

$fileName = sprintf('scroll_%d_%s.%s', $userId, bin2hex(random_bytes(10)), $extension);
$absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $fileName;
$relativePath = $relativeDir . '/' . $fileName;

if (!move_uploaded_file((string)$videoFile['tmp_name'], $absolutePath)) {
    qt_scrolls_respond(['success' => false, 'error' => 'Could not save that Scroll right now.'], 500);
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO scrolls (user_id, video_path, title, caption, mime_type, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([
        $userId,
        str_replace('\\', '/', $relativePath),
        $title !== '' ? $title : null,
        $caption !== '' ? $caption : null,
        $detectedMime !== '' ? $detectedMime : null,
    ]);

    qt_scrolls_respond([
        'success' => true,
        'scroll_id' => (int)$pdo->lastInsertId(),
        'video_path' => str_replace('\\', '/', $relativePath),
        'title' => $title,
    ]);
} catch (Throwable $e) {
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
    error_log('[create_scroll] ' . $e->getMessage());
    qt_scrolls_respond(['success' => false, 'error' => 'Could not post that Scroll right now.'], 500);
}
