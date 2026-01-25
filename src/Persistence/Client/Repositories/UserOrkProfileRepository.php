<?php
declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use DateTime;

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
            ->parkId((int) $playerData['ParkId'])
            ->parkName($parkData['ParkInfo']['ParkName'] ?? null)
            ->kingdomId((int) $playerData['KingdomId'])
            ->kingdomName($parkData['KingdomInfo']['KingdomName'] ?? null)
            ->image($playerData['Image'])
            ->heraldry($playerData['Heraldry'])
            ->suspendedAt($this->parseOrkDate($playerData['SuspendedAt']))
            ->suspendedUntil($this->parseOrkDate($playerData['SuspendedUntil']))
            ->duesThrough($this->parseOrkDate($playerData['DuesThrough']))
            ->updatedAt(new DateTime());

        $entity = $orkProfile->build();

        $this->persist($entity);
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
