<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\RecordSet;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;
use DateTime;
use DateTimeInterface;
use Optional\Optional;

#[RepositoryOf('mailbox_challenges', MailboxChallengeEntity::class)]
class MailboxChallengeRepository extends Repository implements EntityRepositoryInterface
{
    private ?Database $database = null;

    public function useDatabase(Database $database): void
    {
        $this->database = $database;
    }

    public function persist(EntityInterface $entity): EntityInterface
    {
        if (!$entity instanceof MailboxChallengeEntity) {
            return parent::persist($entity);
        }

        $this->clear();
        $this->query(
            'REPLACE INTO mailbox_challenges (
                id, purpose, idp_user_id, mundane_id, code_hash, sent_to_hash, new_email,
                attempts, send_count, stage, expires_at, consumed_at, created_at
            ) VALUES (
                :id, :purpose, NULLIF(:idp_user_id, \'\'), NULLIF(:mundane_id, 0), :code_hash, :sent_to_hash,
                NULLIF(:new_email, \'\'), :attempts, :send_count, NULLIF(:stage, \'\'), :expires_at,
                NULLIF(:consumed_at, \'\'), :created_at
            )',
        );
        $this->__set('id', $entity->getId());
        $this->__set('purpose', $entity->getPurpose());
        $this->__set('idp_user_id', $entity->getIdpUserId() ?? '');
        $this->__set('mundane_id', $entity->getMundaneId() ?? 0);
        $this->__set('code_hash', $entity->getCodeHash());
        $this->__set('sent_to_hash', $entity->getSentToHash());
        $this->__set('new_email', $entity->getNewEmail() ?? '');
        $this->__set('attempts', $entity->getAttempts());
        $this->__set('send_count', $entity->getSendCount());
        $this->__set('stage', $entity->getStage() ?? '');
        $this->__set('expires_at', $entity->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->__set('consumed_at', $entity->getConsumedAt()?->format('Y-m-d H:i:s') ?? '');
        $this->__set('created_at', $entity->getCreatedAt()->format('Y-m-d H:i:s'));
        $this->execute();

        return $entity;
    }

    public function findById(string $id): ?MailboxChallengeEntity
    {
        if ($this->database === null) {
            return $this->fetchBy('id', $id);
        }

        return $this->fetchOne(
            'SELECT id, purpose, idp_user_id, mundane_id, code_hash, sent_to_hash, new_email, attempts, send_count, stage, expires_at, consumed_at, created_at
             FROM mailbox_challenges WHERE id = :id LIMIT 1',
            ['id' => $id],
        );
    }

    /**
     * @return MailboxChallengeEntity[]
     */
    public function findByDestinationAndPurpose(string $sentToHash, string $purpose): array
    {
        if ($this->database === null) {
            return $this->findByDestinationAndPurposeViaActiveRecord($sentToHash, $purpose);
        }

        return $this->fetchAll(
            'SELECT id, purpose, idp_user_id, mundane_id, code_hash, sent_to_hash, new_email, attempts, send_count, stage, expires_at, consumed_at, created_at
             FROM mailbox_challenges WHERE sent_to_hash = :sent_to_hash AND purpose = :purpose',
            [
                'sent_to_hash' => $sentToHash,
                'purpose' => $purpose,
            ],
        );
    }

    public function countSendsSince(string $sentToHash, string $purpose, DateTimeInterface $since): int
    {
        $total = 0;
        foreach ($this->findByDestinationAndPurpose($sentToHash, $purpose) as $row) {
            Optional::ofNullable($row->getCreatedAt())
                ->filter(fn (DateTimeInterface $createdAt) => $createdAt >= $since)
                ->ifPresent(function () use (&$total, $row): void {
                    $total += max(0, $row->getSendCount());
                });
        }

        return $total;
    }

    public function findLatestOpenByUserAndPurpose(string $idpUserId, string $purpose): ?MailboxChallengeEntity
    {
        if ($this->database === null) {
            return $this->findLatestOpenByUserAndPurposeViaActiveRecord($idpUserId, $purpose);
        }

        $latest = null;
        foreach ($this->fetchAll(
            'SELECT id, purpose, idp_user_id, mundane_id, code_hash, sent_to_hash, new_email, attempts, send_count, stage, expires_at, consumed_at, created_at
             FROM mailbox_challenges WHERE idp_user_id = :idp_user_id AND purpose = :purpose',
            [
                'idp_user_id' => $idpUserId,
                'purpose' => $purpose,
            ],
        ) as $row) {
            $latest = Optional::ofNullable($row->getConsumedAt())
                ->map(fn () => $latest)
                ->orElseGet(function () use ($row, $latest) {
                    return Optional::ofNullable($latest)
                        ->filter(fn (MailboxChallengeEntity $current) => $current->getCreatedAt() >= $row->getCreatedAt())
                        ->orElse($row);
                });
        }

        return $latest;
    }

    public function findOpenByMundanePurposeAndHash(int $mundaneId, string $purpose, string $sentToHash): ?MailboxChallengeEntity
    {
        if ($this->database === null) {
            return $this->findOpenByMundanePurposeAndHashViaActiveRecord($mundaneId, $purpose, $sentToHash);
        }

        $open = null;
        foreach ($this->fetchAll(
            'SELECT id, purpose, idp_user_id, mundane_id, code_hash, sent_to_hash, new_email, attempts, send_count, stage, expires_at, consumed_at, created_at
             FROM mailbox_challenges WHERE mundane_id = :mundane_id AND purpose = :purpose AND sent_to_hash = :sent_to_hash',
            [
                'mundane_id' => $mundaneId,
                'purpose' => $purpose,
                'sent_to_hash' => $sentToHash,
            ],
        ) as $row) {
            $open = Optional::ofNullable($row->getConsumedAt())
                ->map(fn () => $open)
                ->orElse($row);
        }

        return $open;
    }

    /**
     * @return MailboxChallengeEntity[]
     */
    private function findByDestinationAndPurposeViaActiveRecord(string $sentToHash, string $purpose): array
    {
        $this->clear();
        $this->sent_to_hash = $sentToHash;
        $this->purpose = $purpose;
        $this->find();

        $rows = [];
        while ($this->next()) {
            $rows[] = $this->getCurrent();
        }

        return $rows;
    }

    private function findLatestOpenByUserAndPurposeViaActiveRecord(string $idpUserId, string $purpose): ?MailboxChallengeEntity
    {
        $this->clear();
        $this->idp_user_id = $idpUserId;
        $this->purpose = $purpose;
        $this->find();

        $latest = null;
        while ($this->next()) {
            /** @var MailboxChallengeEntity $row */
            $row = $this->getCurrent();
            $latest = Optional::ofNullable($row->getConsumedAt())
                ->map(fn () => $latest)
                ->orElseGet(function () use ($row, $latest) {
                    return Optional::ofNullable($latest)
                        ->filter(fn (MailboxChallengeEntity $current) => $current->getCreatedAt() >= $row->getCreatedAt())
                        ->orElse($row);
                });
        }

        return $latest;
    }

    private function findOpenByMundanePurposeAndHashViaActiveRecord(int $mundaneId, string $purpose, string $sentToHash): ?MailboxChallengeEntity
    {
        $this->clear();
        $this->mundane_id = $mundaneId;
        $this->purpose = $purpose;
        $this->sent_to_hash = $sentToHash;
        $this->find();

        $open = null;
        while ($this->next()) {
            /** @var MailboxChallengeEntity $row */
            $row = $this->getCurrent();
            $open = Optional::ofNullable($row->getConsumedAt())
                ->map(fn () => $open)
                ->orElse($row);
        }

        return $open;
    }

    /**
     * @param array<string, string|int> $params
     */
    private function fetchOne(string $sql, array $params): ?MailboxChallengeEntity
    {
        $rows = $this->fetchAll($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, string|int> $params
     * @return MailboxChallengeEntity[]
     */
    private function fetchAll(string $sql, array $params): array
    {
        $database = $this->database;
        if ($database === null) {
            return [];
        }

        $database->clear();
        foreach ($params as $key => $value) {
            $database->__set($key, is_int($value) ? $value : (string) $value);
        }

        $recordSet = $database->execute($sql);
        $rows = [];
        while ($recordSet->next()) {
            $rows[] = $this->hydrate($recordSet);
        }

        return $rows;
    }

    private function hydrate(RecordSet $recordSet): MailboxChallengeEntity
    {
        $consumedRaw = $recordSet->consumed_at ?? null;

        return MailboxChallengeEntity::builder()
            ->id((string) $recordSet->id)
            ->purpose((string) $recordSet->purpose)
            ->idpUserId($recordSet->idp_user_id !== null && $recordSet->idp_user_id !== '' ? (string) $recordSet->idp_user_id : null)
            ->mundaneId($recordSet->mundane_id !== null && (int) $recordSet->mundane_id > 0 ? (int) $recordSet->mundane_id : null)
            ->codeHash((string) $recordSet->code_hash)
            ->sentToHash((string) $recordSet->sent_to_hash)
            ->newEmail($recordSet->new_email !== null && $recordSet->new_email !== '' ? (string) $recordSet->new_email : null)
            ->attempts((int) $recordSet->attempts)
            ->sendCount((int) $recordSet->send_count)
            ->stage($recordSet->stage !== null && $recordSet->stage !== '' ? (string) $recordSet->stage : null)
            ->expiresAt(new DateTime((string) $recordSet->expires_at))
            ->consumedAt(is_string($consumedRaw) && $consumedRaw !== '' ? new DateTime($consumedRaw) : null)
            ->createdAt(new DateTime((string) $recordSet->created_at))
            ->build();
    }

    public static function getTableName(): string
    {
        return 'mailbox_challenges';
    }

    public static function getEntityClass(): string
    {
        return MailboxChallengeEntity::class;
    }
}
