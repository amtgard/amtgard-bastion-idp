<?php

namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserLoginEntity;
use Ramsey\Uuid\Uuid;

#[RepositoryOf("user_logins", UserLoginEntity::class)]
class UserLoginRepository extends Repository implements EntityRepositoryInterface
{
    public function getLoginByProviderId(string $providerId): ?UserLoginEntity
    {
        return $this->fetchBy('providerId', $providerId);
    }

    public function getLoginByUser($user): ?UserLoginEntity
    {
        $this->clear();
        $this->query("select * from user_logins where user_id = :user_id and type = 'local'");
        $this->user_id = $user->getId();
        $this->execute();
        $this->next();
        $login = UserLoginEntity::toRepositoryEntity($this->getEntity());
        $login->user = $user;
        return $login;
    }

    private function configureNewLogin($provider, $user, $password, $avatarUrl): UserLoginEntity
    {
        $login = UserLoginEntity::builder()
            ->user($user)
            ->password(password_hash($password, PASSWORD_DEFAULT))
            ->avatarUrl($avatarUrl)
            ->type($provider)
            ->build();

        EntityManager::getManager()->persist($login);

        return $login;
    }

    public function createLocalLogin($user, $password): UserLoginEntity
    {
        $login = UserLoginEntity::builder()
            ->user($user)
            ->password(password_hash($password, PASSWORD_DEFAULT))
            ->type('local')
            ->build();

        EntityManager::getManager()->persist($login);

        $login->user = $user;

        return $login;
    }

    public function createLoginFromFacebookData(UserEntity $user, array $facebookData, $token): UserLoginEntity
    {
        $login = $this->configureNewLogin('facebook', $user, Uuid::uuid4()->toString(), $facebookData['picture_url']);
        $login->setProviderId($facebookData['id']);
        $this->updateLoginTokens($login, fn($t) => $t->getToken(), $token);
        EntityManager::getManager()->persist($login);
        $login->user = $user;
        return $login;
    }

    public function createLoginFromGoogleData(UserEntity $user, array $googleData, $token): UserLoginEntity
    {
        $login = $this->configureNewLogin('google', $user, Uuid::uuid4()->toString(), $googleData['picture']);
        $login->setProviderId($googleData['sub']);
        $this->updateLoginTokens($login, fn($t) => $t->getRefreshToken(), $token);
        EntityManager::getManager()->persist($login);
        $login->user = $user;
        return $login;
    }

    public function updateLoginTokens(UserLoginEntity $login, callable $refreshTokenAccessor, $token): UserLoginEntity
    {
        $refreshToken = $refreshTokenAccessor($token);

        if ($refreshToken) {
            $login->setRefreshToken($refreshToken);
        }

        if ($token->getExpires()) {
            $expiryDate = (new \DateTime())->setTimestamp($token->getExpires());
            $login->setExpiryDateTime($expiryDate);
        }

        $this->persist($login);
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