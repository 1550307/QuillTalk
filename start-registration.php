<?php
// start-registration.php – REAL USER FLOW (Option 2: No random suffix)

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

require __DIR__ . '/quilltalk-backend/vendor/autoload.php';
require __DIR__ . '/includes/db.php';

use Webauthn\Server;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialSource;

// ------------------------
// Repository
// ------------------------
class MyPublicKeyCredentialSourceRepository implements PublicKeyCredentialSourceRepository {
    private array $storage = [];
    public function saveCredentialSource(PublicKeyCredentialSource $credentialSource): void {
        $this->storage[$credentialSource->getPublicKeyCredentialId()] = $credentialSource;
    }
    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource {
        return $this->storage[$publicKeyCredentialId] ?? null;
    }
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $userEntity): array {
        return [];
    }
}

$credentialRepository = new MyPublicKeyCredentialSourceRepository();
$rpEntity = new PublicKeyCredentialRpEntity('QuillTalk', 'quilltalk.org');

try {
    $server = new Server($rpEntity, $credentialRepository);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server init error: '.$e->getMessage()]);
    exit;
}

// ------------------------
// Validate signup POST data
// ------------------------
$displayName = trim($_POST['display_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (!$displayName || !$email || !$password) {
    http_response_code(400);
    echo json_encode(['error'=>'Missing signup fields']);
    exit;
}

// ------------------------
// Insert real user
// ------------------------
try {
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $username = generate_unique_username($pdo, $displayName);

    $stmt = $pdo->prepare("
        INSERT INTO users (username, display_name, email, password_hash, bio, created_at, is_passkey_user)
        VALUES (?, ?, ?, ?, '', NOW(), 1)
    ");
    $stmt->execute([$username, $displayName, $email, $password_hash]);
    $userId = (string)$pdo->lastInsertId();

} catch (PDOException $e) {
    if ($e->errorInfo[1] == 1062) { // Duplicate entry
        if (str_contains($e->getMessage(), 'users.username')) {
            http_response_code(400);
            echo json_encode(['error' => 'Username already exists. Please choose another.']);
            exit;
        } elseif (str_contains($e->getMessage(), 'users.email')) {
            http_response_code(400);
            echo json_encode(['error' => 'Email already registered. Please use another.']);
            exit;
        }
    }

    http_response_code(500);
    echo json_encode(['error' => 'DB Insert Error: '.$e->getMessage()]);
    exit;
}

// ------------------------
// Create WebAuthn user entity
// ------------------------
$userEntity = new PublicKeyCredentialUserEntity(
    $username,  // account username
    $userId,    // string ID
    $displayName
);

// ------------------------
// Generate creation options
// ------------------------
try {
    $creationOptions = $server->generatePublicKeyCredentialCreationOptions($userEntity,'none');

    // store challenge & user id in session
    $_SESSION['passkey_registration_challenge'] = (string)$creationOptions->getChallenge();
    $_SESSION['passkey_user_id'] = $userId;

    echo json_encode($creationOptions->jsonSerialize());
    exit;
} catch (Throwable $e) {
    // rollback user if WebAuthn fails
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
    http_response_code(500);
    echo json_encode(['error'=>'WebAuthn gen error: '.$e->getMessage()]);
    exit;
}
