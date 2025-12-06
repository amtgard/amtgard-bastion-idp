<?php

namespace Amtgard\IdP\Persistence\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Entities\UserEntity;
use Amtgard\IdP\Persistence\Entities\UserLoginEntity;
use Ramsey\Uuid\Uuid;

#[RepositoryOf("user_logins", UserLoginEntity::class)]
class UserLoginRepository extends Repository implements EntityRepositoryInterface
{
    public function getLoginByGoogleId(string $googleId): ?UserLoginEntity {
        return $this->fetchBy('googleId', $googleId);
    }

    private function configureNewLogin($user, $password, $avatarUrl): UserLoginEntity {
        $login = UserLoginEntity::builder()
            ->user($user)
            ->password(password_hash($password, PASSWORD_DEFAULT))
            ->avatarUrl($avatarUrl)
            ->build();

        EntityManager::getManager()->persist($login);

        return $login;
    }

    public function createLoginFromGoogleData(UserEntity $user, array $googleData): UserLoginEntity {
        $login = $this->configureNewLogin($user, Uuid::uuid4()->toString(), $googleData['picture']);
        $login->setGoogleId($googleData['sub']);
        EntityManager::getManager()->persist($login);
        return $login;
    }

    static function getTableName()
    {
        return 'user_logins';
    }

    public static function getEntityClass()
    {
        return UserLoginEntity::class;
    }

}