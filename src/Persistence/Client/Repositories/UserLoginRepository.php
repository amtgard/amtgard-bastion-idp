<?php

declare(strict_types=1);


namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserLoginEntity;
use Optional\Optional;
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
        $this->user_id = $user->getId();
        $this->type = 'local';
        $this->find();
        if ($this->next()) {
            $login = UserLoginEntity::toRepositoryEntity($this->getEntity());
            $login->user = $user;
            return $login;
        }
        return null;
    }

    public function findLoginById(int $loginId): ?UserLoginEntity
    {
        return $this->fetch($loginId);
    }

    public function loginBelongsToUser(int $loginId, int $userDbId): bool
    {
        return Optional::ofNullable($this->findLoginById($loginId))
            ->map(fn (UserLoginEntity $login) => (int) $login->userId === $userDbId)
            ->orElse(false);
    }

    public function resolveDefaultLoginIdForUser(int $userDbId): ?int
    {
        $this->clear();
        $this->user_id = $userDbId;
        $this->type = 'local';
        $this->limit(0, 1);
        $this->find();
        if ($this->next()) {
            return (int) $this->id;
        }

        $logins = $this->getAllLoginsForUser($userDbId);
        if ($logins === []) {
            return null;
        }

        return (int) $logins[0]->getId();
    }

    /**
     * @param int $userId
     * @return UserLoginEntity[]
     */
    public function getAllLoginsForUser(int $userId): array
    {
        $this->clear();
        $this->user_id = $userId;
        $this->find();

        $logins = [];
        while ($this->next()) {
            $logins[] = UserLoginEntity::toRepositoryEntity($this->getEntity());
        }
        return $logins;
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

    public function createLoginFromProvider(
        string $type,
        UserEntity $user,
        string $providerId,
        string $avatarUrl,
        $token,
        callable $refreshTokenAccessor,
    ): UserLoginEntity {
        $login = $this->configureNewLogin($type, $user, Uuid::uuid4()->toString(), $avatarUrl);
        $login->setProviderId($providerId);
        $this->updateLoginTokens($login, $refreshTokenAccessor, $token);
        EntityManager::getManager()->persist($login);
        $login->user = $user;

        return $login;
    }

    public function createLoginFromFacebookData(UserEntity $user, array $facebookData, $token): UserLoginEntity
    {
        return $this->createLoginFromProvider(
            'facebook',
            $user,
            $facebookData['id'],
            $facebookData['picture_url'],
            $token,
            fn ($t) => $t->getToken(),
        );
    }

    public function createLoginFromGoogleData(UserEntity $user, array $googleData, $token): UserLoginEntity
    {
        return $this->createLoginFromProvider(
            'google',
            $user,
            $googleData['sub'],
            $googleData['picture'],
            $token,
            fn ($t) => $t->getRefreshToken(),
        );
    }

    public function createLoginFromDiscordData(UserEntity $user, array $discordData, $token): UserLoginEntity
    {
        $avatarUrl = 'https://cdn.discordapp.com/embed/avatars/0.png';
        if (!empty($discordData['avatar'])) {
            $avatarUrl = sprintf(
                'https://cdn.discordapp.com/avatars/%s/%s.png',
                $discordData['id'],
                $discordData['avatar']
            );
        }

        return $this->createLoginFromProvider(
            'discord',
            $user,
            $discordData['id'],
            $avatarUrl,
            $token,
            fn ($t) => $t->getRefreshToken(),
        );
    }

    public function createLoginFromAppleData(UserEntity $user, array $appleData, $token): UserLoginEntity
    {
        return $this->createLoginFromProvider(
            'apple',
            $user,
            $appleData['sub'],
            '',
            $token,
            fn ($t) => $t->getRefreshToken(),
        );
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