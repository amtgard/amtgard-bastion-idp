<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Server\Entities\Repository\ClientAccess;

#[RepositoryOf('client_access', ClientAccess::class)]
class ClientAccessRepository extends Repository implements EntityRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'client_access';
    }

    public static function getEntityClass(): string
    {
        return ClientAccess::class;
    }

    /**
     * @return ClientAccess[]
     */
    public function findByClientId(int $clientId): array
    {
        $this->clear();
        $this->client_id = $clientId;
        $this->find();

        $rows = [];
        while ($this->next()) {
            $rows[] = $this->getCurrent();
        }

        return $rows;
    }

    public function hasAccess(int $clientId, int $userId): bool
    {
        $this->clear();
        $this->client_id = $clientId;
        $this->user_id = $userId;

        return $this->find() > 0;
    }

    public function userHasAnyAccess(int $userId): bool
    {
        $this->clear();
        $this->user_id = $userId;

        return $this->find() > 0;
    }

    public function grant(int $clientId, int $userId): void
    {
        if ($this->hasAccess($clientId, $userId)) {
            return;
        }

        $row = ClientAccess::builder()
            ->clientDbId($clientId)
            ->userId($userId)
            ->createdAt(new \DateTimeImmutable())
            ->build();

        $this->persist($row);
    }

    public function revoke(int $clientId, int $userId): void
    {
        $this->clear();
        $this->query('DELETE FROM client_access WHERE client_id = :client_id AND user_id = :user_id');
        $this->client_id = $clientId;
        $this->user_id = $userId;
        $this->execute();
    }
}
