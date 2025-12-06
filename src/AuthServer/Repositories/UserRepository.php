<?php
declare(strict_types=1);

namespace Amtgard\IdP\Auth\Repositories;

use Amtgard\ActiveRecordOrm\Configuration\DataAccessPolicy\UncachedDataAccessPolicy;
use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\ActiveRecordOrm\TableFactory;
use Amtgard\IdP\Auth\Entities\UserEntity as OAuthUserEntity;
use Amtgard\IdP\Persistence\Repositories\UserRepository as UserEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

class UserRepository implements UserRepositoryInterface
{
    public EntityMapper $userRepo;

    public function __construct(Database $database, UncachedDataAccessPolicy $tablePolicy) {
        $this->userRepo = EntityMapper::builder()
            ->table(TableFactory::build($database, $tablePolicy, 'users'))
            ->build();
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
        /** @var UserEntity|null $user */
        $user = $this->userRepo->fetchBy('email', $username);
        
        // Check if user exists and password is valid
        if ($user === null || $user->password === null || !password_verify($password, $user->password)) {
            return null;
        }
        
        $userEntity = new OAuthUserEntity();
        $userEntity->setIdentifier($user->getId());
        
        return $userEntity;
    }
}