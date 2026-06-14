<?php
require 'quilltalk-backend/vendor/autoload.php';
require __DIR__ . '/includes/db.php';
session_start();

$client = new Google_Client();
$client->setClientId('64122317984-3o264u59u57euc5eq6tm6nu44s3buijj.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('https://quilltalk.org/google_callback.php');

if (!isset($_GET['code'])) {
    exit("No code provided.");
}

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
$client->setAccessToken($token);

$googleService = new Google_Service_Oauth2($client);
$googleUser = $googleService->userinfo->get();

// Google gives:
$email = $googleUser->email;
$displayName = $googleUser->name;
$googleId = $googleUser->id;

// check if user exists
$stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? OR email = ?");
$stmt->execute([$googleId, $email]);
$user = $stmt->fetch();

if (!$user) {
    // register user automatically
    $username = generate_unique_username($pdo, (string)$displayName);
    $stmt = $pdo->prepare("
        INSERT INTO users (username, display_name, email, google_id, bio, created_at) 
        VALUES (?, ?, ?, ?, '', NOW())
    ");
    $stmt->execute([$username, $displayName, $email, $googleId]);

    $userId = $pdo->lastInsertId();
} else {
    $userId = $user['id'];
    if (empty($user['display_name'])) {
        $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?")
            ->execute([$displayName, $userId]);
    }
}

// create session token
$sessionToken = bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO sessions (token, user_id) VALUES (?, ?)")
    ->execute([$sessionToken, $userId]);

echo "<script>
    sessionStorage.setItem('quill_token', '$sessionToken');
    window.location.href = 'app.php?token=$sessionToken';
</script>";
exit;
