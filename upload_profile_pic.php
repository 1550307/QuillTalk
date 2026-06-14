<?php
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>$_SERVER['HTTP_HOST'],'secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
if(session_status() === PHP_SESSION_NONE){ session_start(); }

require __DIR__ . '/includes/db.php';

$token = $_POST['token'] ?? '';
if(!$token){ die("missing_token"); }

// get user
$stmt = $pdo->prepare("
    SELECT users.id 
    FROM sessions 
    JOIN users ON sessions.user_id = users.id 
    WHERE sessions.token = ?
");
$stmt->execute([$token]);
$user = $stmt->fetch();

if(!$user){ die("invalid_session"); }

$user_id = $user['id'];

// ---------- FILE CHECKS ----------
if(!isset($_FILES['profile_pic'])){
    die("no_file");
}

$file = $_FILES['profile_pic'];
$allowedMimeToExtension = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
];

if(($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK){
    die("upload_failed");
}

$tmpPath = (string)($file['tmp_name'] ?? '');
$clientMime = trim((string)($file['type'] ?? ''));
$detectedMime = '';

if ($tmpPath !== '' && is_file($tmpPath)) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string)$finfo->file($tmpPath);
}

$resolvedMime = isset($allowedMimeToExtension[$detectedMime])
    ? $detectedMime
    : (isset($allowedMimeToExtension[$clientMime]) ? $clientMime : '');

if($resolvedMime === ''){
    die("invalid_type");
}

if($file['size'] > 5*1024*1024){
    die("too_big");
}

// ---------- GIVE UNIQUE FILE NAME ----------
$ext = $allowedMimeToExtension[$resolvedMime];
$fileName = "profile_{$user_id}_" . time() . "." . $ext;

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "profiles";
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    die("upload_failed");
}

$finalPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
$storedPath = "uploads/profiles/" . $fileName;

// move upload
if(!move_uploaded_file($tmpPath, $finalPath)){
    die("upload_failed");
}

// save to DB
$stmt = $pdo->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
$stmt->execute([$storedPath, $user_id]);

echo "success";
