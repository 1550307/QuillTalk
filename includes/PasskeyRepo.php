<?php
declare(strict_types=1);

use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialUserEntity;

require_once __DIR__ . '/db.php'; // Ensure database connection

class PasskeyRepo implements PublicKeyCredentialSourceRepository
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource
    {
        $stmt = $this->pdo->prepare("SELECT credential_source FROM user_credentials WHERE id = ?");
        $stmt->execute([base64_encode($publicKeyCredentialId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return PublicKeyCredentialSource::createFromArray(json_decode($row['credential_source'], true));
    }

    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        $stmt = $this->pdo->prepare("SELECT credential_source FROM user_credentials WHERE user_id = ?");
        $stmt->execute([$publicKeyCredentialUserEntity->getId()]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sources = [];
        foreach ($rows as $row) {
            $sources[] = PublicKeyCredentialSource::createFromArray(json_decode($row['credential_source'], true));
        }

        return $sources;
    }

    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void
    {
        $data = json_encode($publicKeyCredentialSource);
        $id = base64_encode($publicKeyCredentialSource->getPublicKeyCredentialId());
        $userId = $publicKeyCredentialSource->getUserHandle();

        $stmt = $this->pdo->prepare("INSERT INTO user_credentials (id, user_id, credential_source) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE credential_source = ?");
        $stmt->execute([$id, $userId, $data, $data]);
    }
}