<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'quilltalk-backend/vendor/autoload.php';
require __DIR__ . '/includes/db.php';
session_start();

$client = new Google_Client();
$client->setClientId('64122317984-3o264u59u57euc5eq6tm6nu44s3buijj.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('https://quilltalk.org/google_callback.php');

$client->addScope('email');
$client->addScope('profile');

// redirect user to google login page
header('Location: ' . $client->createAuthUrl());
exit;
