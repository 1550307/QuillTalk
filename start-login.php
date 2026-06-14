<?php
// --- Start Session BEFORE anything else ---
session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/",
    "domain" => "quilltalk.org",
    "secure" => true,
    "httponly" => true,
    "samesite" => "None"
]);
session_start();

header("Content-Type: application/json");

// === 1. Get POST identifier (optional username) ===
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$identifier = trim($data["identifier"] ?? "");

// === 2. Generate challenge ===
$challenge = bin2hex(random_bytes(32));
$challengeB64 = rtrim(strtr(base64_encode($challenge), '+/', '-_'), '=');

// === 3. Look up user and credentials ===
$userId = null;
$allowedCreds = [];

if ($identifier !== "") {
    include "db.php"; // <-- make sure PDO $pdo is here

    // Find user by username or email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :id OR email = :id LIMIT 1");
    $stmt->execute(["id" => $identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $userId = (int)$row["id"];

        // Fetch credentials
        $stmt = $pdo->prepare("SELECT credential_id FROM credentials WHERE user_id = :uid");
        $stmt->execute(["uid" => $userId]);
        $creds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($creds as $cred) {
            $allowedCreds[] = [
                "type" => "public-key",
                "id" => rtrim(strtr(base64_encode($cred), '+/', '-_'), '=')
            ];
        }
    }
}

// === 4. Store challenge + userId in session ===
$_SESSION["login_challenge"] = $challenge;
$_SESSION["login_user_id"] = $userId; // can be null for discoverable creds

// === 5. Build PublicKey options ===
$publicKey = [
    "challenge" => $challengeB64,
    "timeout" => 60000,
    "rpId" => "quilltalk.org",

    // 🦾 SAFE SETTING (never errors)
    "userVerification" => "discouraged",

    // MUST BE AN ARRAY ALWAYS
    "allowCredentials" => $allowedCreds
];

// === 6. Return final JSON ===
echo json_encode([
    "success" => true,
    "challengeToken" => $challengeB64,
    "publicKey" => $publicKey
]);
exit;
