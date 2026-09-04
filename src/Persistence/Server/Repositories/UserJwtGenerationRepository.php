<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserJwtGeneration;
use Amtgard\IdP\Utility\Pvh;

#[RepositoryOf('user_jwt_generations', UserJwtGeneration::class)]
class UserJwtGenerationRepository extends Repository implements EntityRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'user_jwt_generations';
    }

    public static function getEntityClass(): string
    {
        return UserJwtGeneration::class;
    }

    public function findByUserUuidAndAud(string $userUuid, string $aud): ?UserJwtGeneration
    {
        $this->clear();
        $this->user_uuid = $userUuid;
        $this->aud = $aud;
        if ($this->find() === 0) {
            return null;
        }

        /** @var UserJwtGeneration $row */
        $row = $this->getCurrent();
        return $row;
    }

    public function upsert(
        int $userId,
        string $userUuid,
        ?int $clientId,
        string $aud,
        string $policyHash,
        int $nowMs
    ): UserJwtGeneration {
        $now = new \DateTimeImmutable();

        $this->clear();
        $this->user_uuid = $userUuid;
        $this->aud = $aud;
        if ($this->find() > 0) {
            /** @var UserJwtGeneration $existing */
            $existing = $this->getCurrent();
            if (!hash_equals($existing->getPolicyHash(), $policyHash)) {
                $existing->prev_pvh = $existing->getPvh();
                $existing->pvh = Pvh::encode($nowMs, $policyHash);
                $existing->policy_hash = $policyHash;
            }
            $existing->updated_at = $now;
            $this->persist($existing);
            return $existing;
        }

        $row = UserJwtGeneration::builder()
            ->userId($userId)
            ->userUuid($userUuid)
            ->clientId($clientId)
            ->aud($aud)
            ->pvh(Pvh::encode($nowMs, $policyHash))
            ->prevPvh(null)
            ->policyHash($policyHash)
            ->updatedAt($now)
            ->build();
        $this->persist($row);
        return $row;
    }
}
