<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\RecordSet;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Client\Entities\OrkClaimSendNonceEntity;
use DateTimeImmutable;
use DateTimeInterface;

#[RepositoryOf('ork_claim_send_nonces', OrkClaimSendNonceEntity::class)]
final class OrkClaimSendNonceRepository extends Repository implements EntityRepositoryInterface
{
    private ?Database $database = null;

    public function useDatabase(Database $database): void
    {
        $this->database = $database;
    }

    public function insert(string $nonceHash, string $idpUserId, int $mundaneId, DateTimeInterface $expiresAt): void
    {
        $database = $this->requireDatabase();
        $now = new DateTimeImmutable();

        $database->clear();
        $database->__set('nonce_hash', $nonceHash);
        $database->__set('idp_user_id', $idpUserId);
        $database->__set('mundane_id', $mundaneId);
        $database->__set('expires_at', $expiresAt->format('Y-m-d H:i:s'));
        $database->__set('created_at', $now->format('Y-m-d H:i:s'));
        $database->execute(
            'INSERT INTO ork_claim_send_nonces (nonce_hash, idp_user_id, mundane_id, expires_at, consumed_at, created_at)
             VALUES (:nonce_hash, :idp_user_id, :mundane_id, :expires_at, NULL, :created_at)',
        );
    }

    /**
     * @return array{idp_user_id: string, mundane_id: int}|null
     */
    public function consumeValid(string $nonceHash, DateTimeInterface $now): ?array
    {
        $row = $this->fetchRow(
            'SELECT idp_user_id, mundane_id, expires_at, consumed_at
             FROM ork_claim_send_nonces WHERE nonce_hash = :nonce_hash LIMIT 1',
            ['nonce_hash' => $nonceHash],
        );
        if ($row === null) {
            return null;
        }

        if ($row['consumed_at'] !== null && $row['consumed_at'] !== '') {
            return null;
        }

        $expires = new DateTimeImmutable((string) $row['expires_at']);
        if ($expires < $now) {
            return null;
        }

        $database = $this->requireDatabase();
        $database->clear();
        $database->__set('nonce_hash', $nonceHash);
        $database->__set('consumed_at', $now->format('Y-m-d H:i:s'));
        $database->execute(
            'UPDATE ork_claim_send_nonces SET consumed_at = :consumed_at
             WHERE nonce_hash = :nonce_hash AND consumed_at IS NULL',
        );

        return [
            'idp_user_id' => (string) $row['idp_user_id'],
            'mundane_id' => (int) $row['mundane_id'],
        ];
    }

    private function requireDatabase(): Database
    {
        if ($this->database === null) {
            throw new \RuntimeException('OrkClaimSendNonceRepository requires useDatabase()');
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

        return $this->rowToArray($recordSet);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowToArray(RecordSet $recordSet): array
    {
        return [
            'idp_user_id' => $recordSet->idp_user_id ?? null,
            'mundane_id' => $recordSet->mundane_id ?? null,
            'expires_at' => $recordSet->expires_at ?? null,
            'consumed_at' => $recordSet->consumed_at ?? null,
            'username' => $recordSet->username ?? null,
            'id' => $recordSet->id ?? null,
        ];
    }

    public static function getTableName(): string
    {
        return 'ork_claim_send_nonces';
    }

    public static function getEntityClass(): string
    {
        return OrkClaimSendNonceEntity::class;
    }
}
