<?php
declare(strict_types=1);

namespace Amtgard\IdP\Auth\Repositories;

use Doctrine\ORM\EntityManager;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Amtgard\IdP\Entity\User as UserEntity;
use Amtgard\IdP\Auth\Entities\UserEntity as OAuthUserEntity;

class UserRepository implements UserRepositoryInterface
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Get a user entity.
     *
     * @param string                $username
     * @param string                $password
     * @param string                $grantType    The grant type used
     * @param ClientEntityInterface $clientEntity
     *
     * @return UserEntityInterface|null
     */
    public function getUserEntityByUserCredentials($username, $password, $grantType, ClientEntityInterface $clientEntity)
    {
        $userRepo = $this->entityManager->getRepository(UserEntity::class);
        
        /** @var UserEntity|null $user */
        $user = $userRepo->findOneBy(['email' => $username]);
        
        // Check if user exists and password is valid
        if ($user === null || $user->getPassword() === null || !password_verify($password, $user->getPassword())) {
            return null;
        }
        
        $userEntity = new OAuthUserEntity();
        $userEntity->setIdentifier($user->getId());
        
        return $userEntity;
    }
}