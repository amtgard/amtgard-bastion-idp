<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserLoginClient;
use Amtgard\IdP\Utility\ClientMetadataValidator;
use Amtgard\IdP\Utility\Log\UsesIdpLogging;
use Optional\Optional;

#[RepositoryOf('user_login_client', UserLoginClient::class)]
class UserLoginClientRepository extends Repository implements EntityRepositoryInterface
{
    use UsesIdpLogging;

    public static function getTableName(): string
    {
        return 'user_login_client';
    }

    public static function getEntityClass(): string
    {
        return UserLoginClient::class;
    }

    public function getMetadataForJwt(int $loginDbId, int $clientDbId): mixed
    {
        $row = $this->findRow($loginDbId, $clientDbId);
        if ($row === null) {
            $this->logApp()->debug('login client jwt metadata miss', [
                'login_id' => $loginDbId,
                'client_id' => $clientDbId,
            ]);

            return null;
        }

        if ($row->getEncoding() === ClientMetadataValidator::ENCODING_BASE64) {
            return $row->getMetadata();
        }

        return json_decode($row->getMetadata(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{metadata: array<string, mixed>|string, encoding: string}|null
     */
    public function getMetadata(int $loginDbId, int $clientDbId): ?array
    {
        $result = Optional::ofNullable($this->findRow($loginDbId, $clientDbId))
            ->map(function (UserLoginClient $row): array {
                $metadata = $row->getEncoding() === ClientMetadataValidator::ENCODING_BASE64
                    ? $row->getMetadata()
                    : json_decode($row->getMetadata(), true, flags: JSON_THROW_ON_ERROR);

                return [
                    'metadata' => $metadata,
                    'encoding' => $row->getEncoding(),
                ];
            })
            ->orElse(null);

        if ($result === null) {
            $this->logApp()->debug('login client metadata miss', [
                'login_id' => $loginDbId,
                'client_id' => $clientDbId,
            ]);
        }

        return $result;
    }

    public function upsertMetadata(
        int $userDbId,
        int $loginDbId,
        int $clientDbId,
        string $payload,
        string $encoding
    ): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->clear();
        $this->login_id = $loginDbId;
        $this->client_id = $clientDbId;
        if ($this->find() > 0 && $this->next()) {
            /** @var UserLoginClient $existing */
            $existing = $this->getCurrent();
            $existing->replaceMetadata($payload, $encoding, $now);
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
        $this->login_id = $loginDbId;
        $this->client_id = $clientDbId;
        $this->delete(null);

        return true;
    }

    private function findRow(int $loginDbId, int $clientDbId): ?UserLoginClient
    {
        $this->clear();
        $this->login_id = $loginDbId;
        $this->client_id = $clientDbId;
        if ($this->find() === 0 || !$this->next()) {
            return null;
        }

        /** @var UserLoginClient $row */
        $row = $this->getCurrent();
        return $row;
    }
}
