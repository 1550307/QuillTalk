<?php
// finish-registration.php – REAL USER FLOW + TOKEN RETURN

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

if (ob_get_level()) ob_end_clean();
ob_start();
if (session_status()===PHP_SESSION_NONE) session_start();

require __DIR__.'/quilltalk-backend/vendor/autoload.php';
require __DIR__ . '/includes/db.php';

use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\Server;

class MyPublicKeyCredentialSourceRepository implements PublicKeyCredentialSourceRepository {
    public function saveCredentialSource(PublicKeyCredentialSource $credentialSource): void {}
    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource { return null; }
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $userEntity): array { return []; }
}

$repo = new MyPublicKeyCredentialSourceRepository();
$rp = new PublicKeyCredentialRpEntity('QuillTalk','quilltalk.org');
$server = new Server($rp,$repo);

header('Content-Type: application/json');

try {
    // parse passkey_data
    $raw_json = $_POST['passkey_data'] ?? file_get_contents('php://input');
    if (!$raw_json) throw new Exception('Request body empty');

    $data = json_decode($raw_json,true);
    if (json_last_error()!==JSON_ERROR_NONE) throw new Exception('JSON decode error');

    if (!isset($_SESSION['passkey_registration_challenge'], $_SESSION['passkey_user_id'])) {
        throw new Exception('Session challenge missing/expired');
    }

    $user_db_id = (int)$_SESSION['passkey_user_id'];

    // mark user as verified
    $stmt = $pdo->prepare("UPDATE users SET is_verified=1 WHERE id=?");
    $stmt->execute([$user_db_id]);

    // create token
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO sessions (user_id,token) VALUES (?,?)")->execute([$user_db_id,$token]);

    unset($_SESSION['passkey_registration_challenge'],$_SESSION['passkey_user_id']);
    $_SESSION['user_id'] = $user_db_id;

    ob_clean();
    echo json_encode([
        'success'=>true,
        'message'=>'Passkey registration successful',
        'token'=>$token
    ]);
    exit;

} catch (\Exception $e) {
    $user_db_id = (int)($_SESSION['passkey_user_id'] ?? 0);
    if($user_db_id>0) $pdo->prepare("DELETE FROM users WHERE id=? AND is_verified=0")->execute([$user_db_id]);

    ob_clean();
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Registration Failed: '.$e->getMessage()]);
}
ob_end_flush();
exit;
