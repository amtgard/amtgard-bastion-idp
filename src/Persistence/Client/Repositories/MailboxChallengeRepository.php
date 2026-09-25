<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;
use DateTimeInterface;
use Optional\Optional;

#[RepositoryOf('mailbox_challenges', MailboxChallengeEntity::class)]
class MailboxChallengeRepository extends Repository implements EntityRepositoryInterface
{
    public function findById(string $id): ?MailboxChallengeEntity
    {
        return $this->fetchBy('id', $id);
    }

    /**
     * @return MailboxChallengeEntity[]
     */
    public function findByDestinationAndPurpose(string $sentToHash, string $purpose): array
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

    public function findOpenByMundanePurposeAndHash(int $mundaneId, string $purpose, string $sentToHash): ?MailboxChallengeEntity
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

    public static function getTableName(): string
    {
        return 'mailbox_challenges';
    }

    public static function getEntityClass(): string
    {
        return MailboxChallengeEntity::class;
    }
}
