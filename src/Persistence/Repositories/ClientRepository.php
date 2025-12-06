<?php

namespace Amtgard\IdP\Persistence\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Entities\ClientEntity;

#[RepositoryOf('clients', ClientEntity::class)]
class ClientRepository extends Repository implements EntityRepositoryInterface
{
    public function fetchByClientId(string $clientId): ?ClientEntity {
        return $this->fetchBy('clientId', $clientId);
    }

    static function getTableName()
    {
        return 'clients';
    }

    public static function getEntityClass()
    {
        return ClientEntity::class;
    }
}