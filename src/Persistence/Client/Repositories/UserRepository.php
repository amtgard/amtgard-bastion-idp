<?php
declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Client\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Optional\Optional;
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

    public function createUserFromOAuthProfile(string $email, string $firstName, string $lastName): UserEntity
    {
        return $this->configureNewUser($email, $firstName, $lastName);
    }

    public function createUserFromGoogleData(array $googleData): UserEntity {
        return $this->createUserFromOAuthProfile(
            $googleData['email'],
            $googleData['given_name'],
            $googleData['family_name']
        );
    }

    public function createUserFromFacebookData(array $facebookData): UserEntity {
        return $this->createUserFromOAuthProfile(
            $facebookData['email'],
            $facebookData['first_name'],
            $facebookData['last_name']
        );
    }

    public function createUserFromDiscordData(array $discordData): UserEntity
    {
        return $this->createUserFromOAuthProfile(
            $discordData['email'],
            $discordData['username'] ?? '',
            ''
        );
    }

    public function createUserFromAppleData(array $appleData): UserEntity {
        return $this->createUserFromOAuthProfile(
            $appleData['email'],
            $appleData['given_name'] ?? '',
            $appleData['family_name'] ?? ''
        );
    }

    public function findUserByUserId(string $userId): ?UserEntity {
        return $this->fetchBy('user_id', $userId);
    }

    public function updateEmail(UserEntity $user, string $email): void
    {
        $user->setEmail($email);
        $this->persist($user);
    }

    public function findUserById(int $id): ?UserEntity
    {
        /** @var UserEntity|null $user */
        $user = $this->fetch($id);

        return $user;
    }

    /**
     * Type-ahead lookup of existing accounts by email prefix.
     *
     * @return array<int, array{id: int, email: string}>
     */
    public function searchByEmailPrefix(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(25, $limit));
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);

        $this->clear();
        $this->getTable()->like('email', $escaped . '%');
        $this->orderBy('email', OrderBy::ASC);
        $this->limit(0, $limit);
        $this->find();

        $results = [];
        while ($this->next()) {
            /** @var UserEntity $user */
            $user = $this->getCurrent();
            Optional::ofNullable($user->getEmail())
                ->filter(fn (string $email) => $email !== '')
                ->ifPresent(function (string $email) use (&$results, $user): void {
                    $results[] = [
                        'id' => $user->getId(),
                        'email' => $email,
                    ];
                });
        }

        return $results;
    }

    public function getUserEntityById(string $userIdentifier): ?UserEntityInterface {
        return Optional::ofNullable($this->findUserByUserId($userIdentifier))
            ->map(fn (UserEntity $user) => OAuthUser::builder()
                ->identifier($user->getUserId())
                ->userEntity($user)
                ->build())
            ->orElse(null);
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