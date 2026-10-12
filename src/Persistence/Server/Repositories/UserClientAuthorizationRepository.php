<?php

declare(strict_types=1);


namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserClientAuthorization;
use Amtgard\IdP\Utility\Log\UsesIdpLogging;

#[RepositoryOf('user_client_authorizations', UserClientAuthorization::class)]
class UserClientAuthorizationRepository extends Repository implements EntityRepositoryInterface
{
    use UsesIdpLogging;

    static function getTableName()
    {
        return 'user_client_authorizations';
    }

    public static function getEntityClass()
    {
        return UserClientAuthorization::class;
    }

    public function hasAuthorization(string $userIdentifier, int $clientDbId): bool
    {
        $this->clear();
        $this->query(
            'SELECT id FROM user_client_authorizations
             WHERE user_identifier = :user_identifier AND client_id = :client_id
             LIMIT 1',
        );
        $this->user_identifier = $userIdentifier;
        $this->client_id = $clientDbId;
        $this->execute();

        return $this->next();
    }

    public function authorize(string $userIdentifier, int $clientDbId): void
    {
        if ($this->hasAuthorization($userIdentifier, $clientDbId)) {
            $this->logOauth()->debug('user client authorize idempotent noop', [
                'user_identifier' => $userIdentifier,
                'client_id' => $clientDbId,
            ]);

            return;
        }

        $auth = UserClientAuthorization::builder()
            ->userIdentifier($userIdentifier)
            ->clientDbId($clientDbId)
            ->createdAt(new \DateTimeImmutable())
            ->build();

        $this->persist($auth);

        $this->logOauth()->info('user client authorized', [
            'user_identifier' => $userIdentifier,
            'client_id' => $clientDbId,
        ]);
    }

    public function revokeAuthorization(string $userIdentifier, int $clientDbId): void
    {
        $this->clear();
        $this->user_identifier = $userIdentifier;
        $this->client_id = $clientDbId;
        $this->delete(null);

        $this->logOauth()->info('user client authorization revoked', [
            'user_identifier' => $userIdentifier,
            'client_id' => $clientDbId,
        ]);
    }
}
