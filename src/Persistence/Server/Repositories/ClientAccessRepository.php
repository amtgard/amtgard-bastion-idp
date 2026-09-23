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
        return $this->findMatchingRows(['client_id' => $clientId]);
    }

    /**
     * @return ClientAccess[]
     */
    public function findByUserId(int $userId): array
    {
        return $this->findMatchingRows(['user_id' => $userId]);
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
        $this->client_id = $clientId;
        $this->user_id = $userId;
        $this->delete(null);
    }

    /**
     * @param array<string, int> $constraints
     * @return ClientAccess[]
     */
    private function findMatchingRows(array $constraints): array
    {
        $this->clear();
        foreach ($constraints as $field => $value) {
            $this->$field = $value;
        }
        $this->find();

        $rows = [];
        while ($this->next()) {
            $rows[] = $this->getCurrent();
        }

        return $rows;
    }
}
