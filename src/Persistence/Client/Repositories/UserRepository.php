<?php
declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Ramsey\Uuid\Uuid;

#[RepositoryOf("users", UserEntity::class)]
class UserRepository extends Repository implements EntityRepositoryInterface, UserRepositoryInterface
{
    public function userExists(string $email): bool {
        $this->clear();
        $this->email = $email;
        return $this->find() > 0;
    }

    public function getUserByEmail(string $email): ?UserEntity
    {
        return $this->fetchBy('email', $email);
    }

    private function configureNewUser($email, $firstName, $lastName): UserEntity {
        $user = UserEntity::builder()
            ->email($email)
            ->firstName($firstName)
            ->lastName($lastName)
            ->userId(Uuid::uuid4()->toString())
            ->build();

        EntityManager::getManager()->persist($user);

        return $user;
    }

    public function createLocalUser($email, $firstName, $lastName): UserEntity {
        return $this->configureNewUser($email, $firstName, $lastName);
    }

    public function createUserFromGoogleData(array $googleData): UserEntity {
        $user = $this->configureNewUser($googleData['email'], $googleData['given_name'], $googleData['family_name']);
        $user->setUserId(Uuid::uuid4()->toString());
        EntityManager::getManager()->persist($user);
        return $user;
    }

    public function createUserFromFacebookData(array $facebookData): UserEntity {
        $user = $this->configureNewUser($facebookData['email'], $facebookData['first_name'], $facebookData['last_name']);
        $user->setUserId(Uuid::uuid4()->toString());
        EntityManager::getManager()->persist($user);
        return $user;
    }

    public function findUserByUserId(string $userId): ?UserEntity {
        return $this->fetchBy('user_id', $userId);
    }

    public function getUserEntityById(string $userIdentifier): UserEntityInterface {
        /** @var UserEntity $user */
        $user = $this->findUserByUserId($userIdentifier);
        return OAuthUser::builder()
            ->identifier($user->getUserId())
            ->userEntity($user)
            ->build();
    }

    static function getTableName()
    {
        return 'users';
    }

    public static function getEntityClass()
    {
        return UserEntity::class;
    }

    public function getUserEntityByUserCredentials($username, $password, $grantType, ClientEntityInterface $clientEntity)
    {
        // TODO: Implement getUserEntityByUserCredentials() method.
    }
}