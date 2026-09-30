<?php

declare(strict_types=1);

use Amtgard\ActiveRecordOrm\Configuration\DataAccessPolicy\UncachedDataAccessPolicy;
use Amtgard\ActiveRecordOrm\Configuration\Repository\DatabaseConfiguration;
use Amtgard\ActiveRecordOrm\Configuration\Repository\MysqlPdoProvider;
use Amtgard\ActiveRecordOrm\Entity\Policy\RepositoryPolicy;
use Amtgard\ActiveRecordOrm\Entity\Policy\UncachedPolicy;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\DataAccessPolicy;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Repositories\AccessTokenRepository;
use Amtgard\IdP\Persistence\Server\Repositories\AuthCodeRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RefreshTokenRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ScopeRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Psr\Container\ContainerInterface;

return [
    Database::class => function (ContainerInterface $container) {
        $config = DatabaseConfiguration::fromEnvironment();
        $provider = MysqlPdoProvider::fromConfiguration($config);
        return Database::fromProvider($provider);
    },

    DataAccessPolicy::class => function (ContainerInterface $container) {
        $database = $container->get(Database::class);
        return UncachedDataAccessPolicy::builder()->database($database)->build();
    },

    UncachedDataAccessPolicy::class => function (ContainerInterface $container) {
        $database = $container->get(Database::class);
        return UncachedDataAccessPolicy::builder()->database($database)->build();
    },

    RepositoryPolicy::class => function (ContainerInterface $container) {
        return UncachedPolicy::builder()->build();
    },

    UserClientAuthorizationRepository::class => function (EntityManager $em) {
        return $em->getRepository(UserClientAuthorizationRepository::class);
    },

    ClientAccessRepository::class => function (EntityManager $em) {
        return $em->getRepository(ClientAccessRepository::class);
    },

    UserLoginClientRepository::class => function (EntityManager $em) {
        return $em->getRepository(UserLoginClientRepository::class);
    },

    UserJwtGenerationRepository::class => function (EntityManager $em) {
        return $em->getRepository(UserJwtGenerationRepository::class);
    },

    EntityManager::class => function (ContainerInterface $container) {
        $em = EntityManager::builder()
            ->database($container->get(Database::class))
            ->dataAccessPolicy($container->get(DataAccessPolicy::class))
            ->repositoryPolicy($container->get(RepositoryPolicy::class))
            ->build();
        EntityManager::configure($em);
        return $em;
    },

    UserRepository::class => function (EntityManager $em) {
        return $em->getRepository(UserRepository::class);
    },

    UserRepositoryInterface::class => function (EntityManager $em) {
        return $em->getRepository(UserRepository::class);
    },

    UserLoginRepository::class => function (EntityManager $em) {
        return $em->getRepository(UserLoginRepository::class);
    },

    UserOrkProfileRepository::class => function (EntityManager $em) {
        return $em->getRepository(UserOrkProfileRepository::class);
    },

    ClientRepositoryInterface::class => function (EntityManager $em) {
        return $em->getRepository(ClientRepository::class);
    },

    ScopeRepositoryInterface::class => function (EntityManager $em) {
        return $em->getRepository(ScopeRepository::class);
    },

    AccessTokenRepositoryInterface::class => function (EntityManager $em) {
        return $em->getRepository(AccessTokenRepository::class);
    },

    AuthCodeRepositoryInterface::class => function (EntityManager $em) {
        return $em->getRepository(AuthCodeRepository::class);
    },

    ClientRepository::class => function (EntityManager $em) {
        return $em->getRepository(ClientRepository::class);
    },

    RefreshTokenRepositoryInterface::class => function (EntityManager $em) {
        return $em->getRepository(RefreshTokenRepository::class);
    },
];
