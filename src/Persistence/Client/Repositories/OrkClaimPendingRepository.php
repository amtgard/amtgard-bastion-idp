<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\RecordSet;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Client\Entities\OrkClaimPendingEntity;
use DateTimeImmutable;
use DateTimeInterface;
use Ramsey\Uuid\Uuid;

#[RepositoryOf('ork_claim_pending', OrkClaimPendingEntity::class)]
final class OrkClaimPendingRepository extends Repository implements EntityRepositoryInterface
{
    private ?Database $database = null;

    public function useDatabase(Database $database): void
    {
        $this->database = $database;
    }

    public function replaceOpen(string $idpUserId, int $mundaneId, string $username, DateTimeInterface $expiresAt): string
    {
        $database = $this->requireDatabase();
        $now = new DateTimeImmutable();

        $database->clear();
        $database->__set('completed_at', $now->format('Y-m-d H:i:s'));
        $database->__set('idp_user_id', $idpUserId);
        $database->execute(
            'UPDATE ork_claim_pending SET completed_at = :completed_at
             WHERE idp_user_id = :idp_user_id AND completed_at IS NULL',
        );

        $id = Uuid::uuid4()->toString();
        $database->clear();
        $database->__set('id', $id);
        $database->__set('idp_user_id', $idpUserId);
        $database->__set('mundane_id', $mundaneId);
        $database->__set('username', $username);
        $database->__set('expires_at', $expiresAt->format('Y-m-d H:i:s'));
        $database->__set('created_at', $now->format('Y-m-d H:i:s'));
        $database->execute(
            'INSERT INTO ork_claim_pending (id, idp_user_id, mundane_id, username, expires_at, completed_at, created_at)
             VALUES (:id, :idp_user_id, :mundane_id, :username, :expires_at, NULL, :created_at)',
        );

        return $id;
    }

    /**
     * @return array{id: string, mundane_id: int, username: string}|null
     */
    public function findOpenByIdpUserId(string $idpUserId, DateTimeInterface $now): ?array
    {
        $row = $this->fetchRow(
            'SELECT id, mundane_id, username, expires_at, completed_at
             FROM ork_claim_pending
             WHERE idp_user_id = :idp_user_id AND completed_at IS NULL
             ORDER BY created_at DESC LIMIT 1',
            ['idp_user_id' => $idpUserId],
        );
        if ($row === null) {
            return null;
        }

        $expires = new DateTimeImmutable((string) $row['expires_at']);
        if ($expires < $now) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'mundane_id' => (int) $row['mundane_id'],
            'username' => (string) $row['username'],
        ];
    }

    public function complete(string $id): void
    {
        $database = $this->requireDatabase();
        $now = new DateTimeImmutable();
        $database->clear();
        $database->__set('completed_at', $now->format('Y-m-d H:i:s'));
        $database->__set('id', $id);
        $database->execute(
            'UPDATE ork_claim_pending SET completed_at = :completed_at WHERE id = :id',
        );
    }

    private function requireDatabase(): Database
    {
        if ($this->database === null) {
            throw new \RuntimeException('OrkClaimPendingRepository requires useDatabase()');
        }

        return $this->database;
    }

    /**
     * @param array<string, string|int> $params
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $sql, array $params): ?array
    {
        $database = $this->database;
        if ($database === null) {
            return null;
        }

        $database->clear();
        foreach ($params as $key => $value) {
            $database->__set($key, is_int($value) ? $value : (string) $value);
        }

        $recordSet = $database->execute($sql);
        if (!$recordSet->next()) {
            return null;
        }

        return [
            'id' => $recordSet->id ?? null,
            'mundane_id' => $recordSet->mundane_id ?? null,
            'username' => $recordSet->username ?? null,
            'expires_at' => $recordSet->expires_at ?? null,
            'consumed_at' => $recordSet->consumed_at ?? null,
        ];
    }

    public static function getTableName(): string
    {
        return 'ork_claim_pending';
    }

    public static function getEntityClass(): string
    {
        return OrkClaimPendingEntity::class;
    }
}
