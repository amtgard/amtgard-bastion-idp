<?php
declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use Amtgard\IdP\Services\Ork\OrkPublicAssetUrl;
use Amtgard\IdP\Utility\Log\UsesIdpLogging;
use DateTime;
use Optional\Optional;

#[RepositoryOf("user_ork_profiles", UserOrkProfileEntity::class)]
class UserOrkProfileRepository extends Repository implements EntityRepositoryInterface
{
    use UsesIdpLogging;

    public function findByUserId(int $userId): ?UserOrkProfileEntity
    {
        return $this->fetchBy('user_id', $userId);
    }

    public function findByMundaneId(int $mundaneId): ?UserOrkProfileEntity
    {
        return $this->fetchBy('mundane_id', $mundaneId);
    }

    private function parseOrkDate(?string $dateStr): ?DateTime
    {
        if (empty($dateStr) || $dateStr === '0000-00-00') {
            return null;
        }
        try {
            return new DateTime($dateStr);
        } catch (\Exception $e) {
            $this->logApp()->debug('ork profile date parse failed', ['field' => 'ork_date']);

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

        $parkId = Optional::ofNullable($playerData['ParkId'] ?? null)
            ->map(fn($v) => (int) $v)
            ->filter(fn(int $id) => $id > 0)
            ->orElse(null);
        if ($parkId === null && ($playerData['ParkId'] ?? null) !== null) {
            $this->logApp()->debug('ork profile optional field omitted', ['user_id' => $userId, 'field' => 'park_id']);
        }

        $kingdomId = Optional::ofNullable($playerData['KingdomId'] ?? null)
            ->map(fn($v) => (int) $v)
            ->filter(fn(int $id) => $id > 0)
            ->orElse(null);
        if ($kingdomId === null && ($playerData['KingdomId'] ?? null) !== null) {
            $this->logApp()->debug('ork profile optional field omitted', ['user_id' => $userId, 'field' => 'kingdom_id']);
        }

        $orkProfile
            ->orkToken($token)
            ->mundaneId((int) $playerData['MundaneId'])
            ->username($playerData['UserName'])
            ->persona($playerData['Persona'])
            ->suspended((int) $playerData['Suspended'])
            ->email($playerData['Email'])
            ->parkId($parkId)
            ->parkName($parkData['ParkInfo']['ParkName'] ?? null)
            ->kingdomId($kingdomId)
            ->kingdomName($parkData['KingdomInfo']['KingdomName'] ?? null)
            ->image(OrkPublicAssetUrl::forBrowser(isset($playerData['Image']) ? (string) $playerData['Image'] : null))
            ->heraldry(OrkPublicAssetUrl::forBrowser(isset($playerData['Heraldry']) ? (string) $playerData['Heraldry'] : null))
            ->suspendedAt($this->parseOrkDate($playerData['SuspendedAt']))
            ->suspendedUntil($this->parseOrkDate($playerData['SuspendedUntil']))
            ->duesThrough($this->parseOrkDate($playerData['DuesThrough']))
            ->updatedAt(new DateTime());

        $entity = $orkProfile->build();

        $this->persistProfile($entity, $userId, (int) $playerData['MundaneId']);
    }

    public function linkExistingUserToMundane(int $userId, int $mundaneId, string $linkedVia): void
    {
        $existingOpt = Optional::ofNullable($this->findByUserId($userId));
        if ($existingOpt->isPresent()) {
            $existing = $existingOpt->get();
            if ($existing->getMundaneId() === $mundaneId) {
                $this->logApp()->debug('ork profile link idempotent noop', [
                    'user_id' => $userId,
                    'mundane_id' => $mundaneId,
                ]);

                return;
            }
            $this->logApp()->error('ork profile link conflict', [
                'user_id' => $userId,
                'existing_mundane_id' => $existing->getMundaneId(),
                'requested_mundane_id' => $mundaneId,
            ]);
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

    private function persistProfile(UserOrkProfileEntity $entity, int $userId, int $mundaneId): void
    {
        try {
            $this->persist($entity);
        } catch (\PDOException $e) {
            $sqlstate = (string) $e->getCode();
            $isIntegrity = $sqlstate === '23000' || str_contains($e->getMessage(), 'Duplicate');
            if (!$isIntegrity) {
                throw $e;
            }
            $this->logApp()->error('ork profile persist integrity conflict', [
                'user_id' => $userId,
                'mundane_id' => $mundaneId,
                'sqlstate' => $sqlstate,
            ]);
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
