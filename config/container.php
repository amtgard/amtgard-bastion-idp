<?php
declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Amtgard\IdP\Auth\Repositories\AccessTokenRepository;
use Amtgard\IdP\Auth\Repositories\AuthCodeRepository;
use Amtgard\IdP\Auth\Repositories\ClientRepository;
use Amtgard\IdP\Auth\Repositories\RefreshTokenRepository;
use Amtgard\IdP\Auth\Repositories\ScopeRepository;
use Amtgard\IdP\Auth\Repositories\UserRepository;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

return [
    // Logger
    LoggerInterface::class => function () {
        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', Logger::DEBUG));
        return $logger;
    },

    // Database connection
    'db.connection' => function () {
        return DriverManager::getConnection([
            'driver' => $_ENV['DB_DRIVER'],
            'host' => $_ENV['DB_HOST'],
            'port' => $_ENV['DB_PORT'],
            'dbname' => $_ENV['DB_NAME'],
            'user' => $_ENV['DB_USER'],
            'password' => $_ENV['DB_PASS'],
            'charset' => 'utf8mb4'
        ]);
    },

    // Entity Manager
    EntityManager::class => function (ContainerInterface $container) {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__ . '/../src/Entity'],
            $_ENV['APP_DEBUG'] == 'true',
            null,
            null
        );
        
        return EntityManager::create(
            $container->get('db.connection'),
            $config
        );
    },

    // OAuth2 Authorization Server
    AuthorizationServer::class => function (ContainerInterface $container) {
        // Init repositories
        $clientRepository = $container->get(ClientRepository::class);
        $scopeRepository = $container->get(ScopeRepository::class);
        $accessTokenRepository = $container->get(AccessTokenRepository::class);
        $authCodeRepository = $container->get(AuthCodeRepository::class);
        $refreshTokenRepository = $container->get(RefreshTokenRepository::class);

        // Path to private key
        $privateKey = new CryptKey(
            $_ENV['OAUTH_PRIVATE_KEY'],
            null,
            false
        );

        // Setup the authorization server
        $server = new AuthorizationServer(
            $clientRepository,
            $accessTokenRepository,
            $scopeRepository,
            $privateKey,
            $_ENV['OAUTH_ENCRYPTION_KEY']
        );

        // Enable the authentication code grant on the server with a token TTL of 1 hour
        $authCodeGrant = new AuthCodeGrant(
            $authCodeRepository,
            $refreshTokenRepository,
            new \DateInterval('PT10M') // Authorization codes will expire after 10 minutes
        );
        $authCodeGrant->setRefreshTokenTTL(new \DateInterval('P1M')); // Refresh tokens will expire after 1 month

        // Enable the refresh token grant on the server
        $refreshTokenGrant = new RefreshTokenGrant($refreshTokenRepository);
        $refreshTokenGrant->setRefreshTokenTTL(new \DateInterval('P1M')); // Refresh tokens will expire after 1 month

        $server->enableGrantType(
            $authCodeGrant,
            new \DateInterval('PT1H') // Access tokens will expire after 1 hour
        );

        $server->enableGrantType(
            $refreshTokenGrant,
            new \DateInterval('PT1H') // Access tokens will expire after 1 hour
        );

        return $server;
    },

    // OAuth2 Resource Server
    ResourceServer::class => function (ContainerInterface $container) {
        $publicKey = new CryptKey(
            $_ENV['OAUTH_PUBLIC_KEY'],
            null,
            false
        );

        return new ResourceServer(
            $container->get(AccessTokenRepository::class),
            $publicKey
        );
    },

    // Google OAuth Provider
    Google::class => function () {
        return new Google([
            'clientId'     => $_ENV['GOOGLE_CLIENT_ID'],
            'clientSecret' => $_ENV['GOOGLE_CLIENT_SECRET'],
            'redirectUri'  => $_ENV['GOOGLE_REDIRECT_URI'],
            'scopes'       => ['email', 'profile'],
        ]);
    },

    // Facebook OAuth Provider
    Facebook::class => function () {
        return new Facebook([
            'clientId'     => $_ENV['FACEBOOK_CLIENT_ID'],
            'clientSecret' => $_ENV['FACEBOOK_CLIENT_SECRET'],
            'redirectUri'  => $_ENV['FACEBOOK_REDIRECT_URI'],
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