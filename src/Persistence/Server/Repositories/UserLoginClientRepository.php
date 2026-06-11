<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserLoginClient;
use Amtgard\IdP\Utility\ClientMetadataValidator;
use Optional\Optional;

#[RepositoryOf('user_login_client', UserLoginClient::class)]
class UserLoginClientRepository extends Repository implements EntityRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'user_login_client';
    }

    public static function getEntityClass(): string
    {
        return UserLoginClient::class;
    }

    /**
     * Shapes the stored blob for the JWT claim: json rows become objects, base64 rows stay opaque strings
     * because integrators requested on-wire encoding without IDP re-serialization.
     */
    public function getMetadataForJwt(int $loginDbId, int $clientDbId): mixed
    {
        return Optional::ofNullable($this->findRow($loginDbId, $clientDbId))
            ->map(function (UserLoginClient $row): mixed {
                if ($row->getEncoding() === ClientMetadataValidator::ENCODING_BASE64) {
                    return $row->getMetadata();
                }

                return json_decode($row->getMetadata(), true, flags: JSON_THROW_ON_ERROR);
            })
            ->orElse(null);
    }

    /**
     * @return array{metadata: array<string, mixed>|string, encoding: string}|null
     */
    public function getMetadata(int $loginDbId, int $clientDbId): ?array
    {
        $row = $this->findRow($loginDbId, $clientDbId);
        if ($row === null) {
            return null;
        }

        if ($row->getEncoding() === ClientMetadataValidator::ENCODING_BASE64) {
            $metadata = $row->getMetadata();
        } else {
            $metadata = json_decode($row->getMetadata(), true, flags: JSON_THROW_ON_ERROR);
        }

        return [
            'metadata' => $metadata,
            'encoding' => $row->getEncoding(),
        ];
    }

    public function upsertMetadata(
        int $userDbId,
        int $loginDbId,
        int $clientDbId,
        string $payload,
        string $encoding
    ): void {
        $now = new \DateTimeImmutable();

        $this->clear();
        $this->login_id = $loginDbId;
        $this->client_id = $clientDbId;
        if ($this->find() > 0) {
            /** @var UserLoginClient $existing */
            $existing = $this->getCurrent();
            $existing->metadata = $payload;
            $existing->encoding = $encoding;
            $existing->updated_at = $now;
            $this->persist($existing);
            return;
        }

        $row = UserLoginClient::builder()
            ->userId($userDbId)
            ->loginId($loginDbId)
            ->clientDbId($clientDbId)
            ->metadata($payload)
            ->encoding($encoding)
            ->updatedAt($now)
            ->build();
        $this->persist($row);
    }

    public function deleteMetadata(int $loginDbId, int $clientDbId): bool
    {
        $this->clear();
        $this->query(
            'DELETE FROM user_login_client WHERE login_id = :login_id AND client_id = :client_id'
        );
        $this->login_id = $loginDbId;
        $this->client_id = $clientDbId;
        $this->execute();

        return true;
    }

    private function findRow(int $loginDbId, int $clientDbId): ?UserLoginClient
    {
        $this->clear();
        $this->login_id = $loginDbId;
        $this->client_id = $clientDbId;
        if ($this->find() === 0) {
            return null;
        }

        /** @var UserLoginClient $row */
        $row = $this->getCurrent();
        return $row;
    }
}
