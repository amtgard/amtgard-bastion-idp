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
use Amtgard\IdP\Middleware\AuthMiddleware;
use Amtgard\IdP\Middleware\ManagementMiddleware;
use Amtgard\IdP\Models\OAuthServerConfiguration;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Repositories\AccessTokenRepository;
use Amtgard\IdP\Persistence\Server\Repositories\AuthCodeRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RefreshTokenRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ScopeRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

return [
        // Logger
    LoggerInterface::class => function () {
        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', Logger::DEBUG));
        return $logger;
    },

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

    UserClientAuthorizationRepository::class => function (ContainerInterface $container) {
        return EntityManager::getManager()->getRepository(UserClientAuthorizationRepository::class);
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

    UserRepository::class => function (ContainerInterface $container) {
        return EntityManager::getManager()->getRepository(UserRepository::class);
    },

    UserRepositoryInterface::class => function (ContainerInterface $container) {
        return EntityManager::getManager()->getRepository(UserRepository::class);
    },

    UserLoginRepository::class => function (ContainerInterface $container) {
        return EntityManager::getManager()->getRepository(UserLoginRepository::class);
    },

    ClientRepositoryInterface::class => function (ContainerInterface $container) {
        return EntityManager::getManager()->getRepository(ClientRepository::class);
    },

    ScopeRepositoryInterface::class => function (ContainerInterface $container) {
        return EntityManager::getManager()->getRepository(ScopeRepository::class);
    },

    AccessTokenRepositoryInterface::class => function (ContainerInterface $container) {
        return EntityManager::getManager()->getRepository(AccessTokenRepository::class);
    },

    AuthCodeRepositoryInterface::class => function (ContainerInterface $container) {
        return EntityManager::getManager()->getRepository(AuthCodeRepository::class);
    },

    AuthMiddleware::class => function (ContainerInterface $container) {
        return new AuthMiddleware($container->get(EntityManager::class), $container->get(LoggerInterface::class), $container->get(ResourceServer::class));
    },

    ManagementMiddleware::class => function (ContainerInterface $container) {
        return new ManagementMiddleware();
    },

    RefreshTokenRepositoryInterface::class => function (ContainerInterface $container) {
        return EntityManager::getManager()->getRepository(RefreshTokenRepository::class);
    },

    OAuthServerConfiguration::class => function (ContainerInterface $container) {
        return OAuthServerConfiguration::builder()
            ->clientRepository($container->get(ClientRepositoryInterface::class))
            ->scopeRepository($container->get(ScopeRepositoryInterface::class))
            ->accessTokenRepository($container->get(AccessTokenRepositoryInterface::class))
            ->authCodeRepository($container->get(AuthCodeRepositoryInterface::class))
            ->refreshTokenRepository($container->get(RefreshTokenRepositoryInterface::class))
            ->build();
    },

        // OAuth2 Authorization Server
    AuthorizationServer::class => function (ContainerInterface $container) {
        return $container->get(OAuthServerConfiguration::class)->build();
    },

        // OAuth2 Resource Server
    ResourceServer::class => function (ContainerInterface $container) {
        $publicKey = new CryptKey(
            $_ENV['OAUTH_PUBLIC_KEY'],
            null,
            false
        );

        return new ResourceServer(
            $container->get(AccessTokenRepositoryInterface::class),
            $publicKey
        );
    },

        // Google OAuth Provider
    Google::class => function () {
        return new Google([
            'clientId' => $_ENV['GOOGLE_CLIENT_ID'],
            'clientSecret' => $_ENV['GOOGLE_CLIENT_SECRET'],
            'redirectUri' => $_ENV['GOOGLE_REDIRECT_URI'],
            'scopes' => ['email', 'profile'],
        ]);
    },

        // Facebook OAuth Provider
    Facebook::class => function () {
        return new Facebook([
            'clientId' => $_ENV['FACEBOOK_CLIENT_ID'],
            'clientSecret' => $_ENV['FACEBOOK_CLIENT_SECRET'],
            'redirectUri' => $_ENV['FACEBOOK_REDIRECT_URI'],
            'graphApiVersion' => 'v12.0',
        ]);
    },

        // Twig Environment
    TwigEnvironment::class => function () {
        $loader = new FilesystemLoader(__DIR__ . '/../templates');
        return new TwigEnvironment($loader, [
            'cache' => __DIR__ . '/cache/twig',
            'auto_reload' => true,
        ]);
    },
];