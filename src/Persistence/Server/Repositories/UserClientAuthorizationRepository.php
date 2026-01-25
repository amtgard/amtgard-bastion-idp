<?php

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserClientAuthorization;

#[RepositoryOf('user_client_authorizations', UserClientAuthorization::class)]
class UserClientAuthorizationRepository extends Repository implements EntityRepositoryInterface
{
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
        $this->user_identifier = $userIdentifier;
        $this->client_id = $clientDbId;
        return $this->find() > 0;
    }

    public function authorize(string $userIdentifier, int $clientDbId): void
    {
        if ($this->hasAuthorization($userIdentifier, $clientDbId)) {
            return;
        }

        $auth = UserClientAuthorization::builder()
            ->userIdentifier($userIdentifier)
            ->clientDbId($clientDbId)
            ->build();

        $this->persist($auth);
    }
}
