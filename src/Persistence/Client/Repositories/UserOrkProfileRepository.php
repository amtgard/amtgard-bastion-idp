<?php
declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use DateTime;
use Optional\Optional;

#[RepositoryOf("user_ork_profiles", UserOrkProfileEntity::class)]
class UserOrkProfileRepository extends Repository implements EntityRepositoryInterface
{
    public function findByUserId(int $userId): ?UserOrkProfileEntity
    {
        return $this->fetchBy('user_id', $userId);
    }

    private function parseOrkDate(?string $dateStr): ?DateTime
    {
        if (empty($dateStr) || $dateStr === '0000-00-00') {
            return null;
        }
        try {
            return new DateTime($dateStr);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function saveOrUpdateProfile(array $playerData, ?array $parkData, string $token, int $userId): void
    {
        $existing = $this->findByUserId($userId);

        if ($existing) {
            $orkProfile = $existing->toBuilder();
        } else {
            $orkProfile = UserOrkProfileEntity::builder()
                ->userId($userId)
                ->createdAt(new DateTime());
        }

        $orkProfile
            ->orkToken($token)
            ->mundaneId((int) $playerData['MundaneId'])
            ->username($playerData['UserName'])
            ->persona($playerData['Persona'])
            ->suspended((int) $playerData['Suspended'])
            ->email($playerData['Email'])
            ->parkId(
                Optional::ofNullable($playerData['ParkId'] ?? null)
                    ->map(fn($v) => (int) $v)
                    ->filter(fn(int $id) => $id > 0)
                    ->orElse(null)
            )
            ->parkName($parkData['ParkInfo']['ParkName'] ?? null)
            ->kingdomId(
                Optional::ofNullable($playerData['KingdomId'] ?? null)
                    ->map(fn($v) => (int) $v)
                    ->filter(fn(int $id) => $id > 0)
                    ->orElse(null)
            )
            ->kingdomName($parkData['KingdomInfo']['KingdomName'] ?? null)
            ->image($playerData['Image'])
            ->heraldry($playerData['Heraldry'])
            ->suspendedAt($this->parseOrkDate($playerData['SuspendedAt']))
            ->suspendedUntil($this->parseOrkDate($playerData['SuspendedUntil']))
            ->duesThrough($this->parseOrkDate($playerData['DuesThrough']))
            ->updatedAt(new DateTime());

        $entity = $orkProfile->build();

        $this->persistProfile($entity, $userId, (int) $playerData['MundaneId']);
    }

    /**
     * Idempotently link an existing IDP user to an ORK mundane.
     *
     * - If no profile exists, create a minimal placeholder row tagged with $linkedVia.
     *   The persona/username/ork_token fields are left blank; they get populated on the
     *   user's next userinfo round-trip via saveOrUpdateProfile().
     * - If a profile exists pointing at the same mundane, no-op.
     * - If a profile exists pointing at a different mundane, throw RuntimeException
     *   with 'conflict' in the message — the caller turns this into a 409 to the client.
     */
    public function linkExistingUserToMundane(int $userId, int $mundaneId, string $linkedVia): void
    {
        $existingOpt = Optional::ofNullable($this->findByUserId($userId));
        if ($existingOpt->isPresent()) {
            $existing = $existingOpt->get();
            if ($existing->getMundaneId() === $mundaneId) {
                return;
            }
            throw new \RuntimeException(
                "conflict: user_id={$userId} is already linked to mundane_id={$existing->getMundaneId()}, refusing to relink to {$mundaneId}"
            );
        }

        $now = new DateTime();
        $entity = UserOrkProfileEntity::builder()
            ->userId($userId)
            ->linkedVia($linkedVia)
            ->orkToken('')
            ->mundaneId($mundaneId)
            ->username('')
            ->persona('')
            ->suspended(0)
            ->email(null)
            ->parkId(null)
            ->kingdomId(null)
            ->createdAt($now)
            ->updatedAt($now)
            ->build();

        $this->persistProfile($entity, $userId, $mundaneId);
    }

    /**
     * The unique indexes on user_id and mundane_id reject a second link.
     * Translate that into the conflict the HTTP callers already turn into a
     * friendly error, instead of a raw integrity-constraint 500.
     */
    private function persistProfile(UserOrkProfileEntity $entity, int $userId, int $mundaneId): void
    {
        try {
            $this->persist($entity);
        } catch (\PDOException $e) {
            $sqlstate = $e->getCode();
            $isIntegrity = $sqlstate === '23000' || str_contains($e->getMessage(), 'Duplicate');
            if (!$isIntegrity) {
                throw $e;
            }
            $currentOpt = Optional::ofNullable($this->findByUserId($userId));
            if ($currentOpt->isPresent()) {
                $current = $currentOpt->get();
                if ($current->getMundaneId() === $mundaneId) {
                    return;
                }
                throw new \RuntimeException(
                    "conflict: user_id={$userId} is already linked to mundane_id={$current->getMundaneId()}, refusing to relink to {$mundaneId}"
                );
            }
            throw new \RuntimeException(
                "conflict: mundane_id={$mundaneId} is already linked to a different IDP user"
            );
        }
    }

    /**
     * Removes the ORK profile row for this IDP user when one exists.
     * Returns false when the account has nothing linked.
     */
    public function unlinkByUserId(int $userId): bool
    {
        if (!Optional::ofNullable($this->findByUserId($userId))->isPresent()) {
            return false;
        }

        $this->clear();
        $this->user_id = $userId;
        $this->delete(null);

        return true;
    }

    static function getTableName()
    {
        return 'user_ork_profiles';
    }

    public static function getEntityClass()
    {
        return UserOrkProfileEntity::class;
    }

}
