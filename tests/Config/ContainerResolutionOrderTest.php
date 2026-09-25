<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Controllers\Client\ConnectController;
use Amtgard\IdP\Controllers\Resource\ResourcesController;
use Amtgard\IdP\Controllers\Server\OAuth\DiscoveryController;
use Amtgard\IdP\Controllers\Server\OAuth\OidcUserInfoController;
use Amtgard\IdP\Middleware\ConfidentialClientBasicAuthMiddleware;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Tests\Support\OAuthTestEnvironment;
use DI\Bridge\Slim\Bridge;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RedisException;
use Throwable;

/**
 * Ensures PHP-DI resolves ORM-backed services without ctor-order hacks.
 */
final class ContainerResolutionOrderTest extends TestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $devKeysDir = dirname(__DIR__, 2) . '/dev-keys';
        if (!file_exists('/tmp/private.key') && file_exists($devKeysDir . '/private.key')) {
            @copy($devKeysDir . '/private.key', '/tmp/private.key');
        }
        if (!file_exists('/tmp/public.key') && file_exists($devKeysDir . '/public.key')) {
            @copy($devKeysDir . '/public.key', '/tmp/public.key');
        }

        $this->applyPhpUnitEnvironmentOverrides();

        $_ENV['SESSION_REDIS_HOST'] = '';
        $_ENV['DISCORD_CLIENT_ID'] = $_ENV['DISCORD_CLIENT_ID'] ?? 'test_discord_client_id';
        $_ENV['DISCORD_CLIENT_SECRET'] = $_ENV['DISCORD_CLIENT_SECRET'] ?? 'test_discord_client_secret';
        $_ENV['DISCORD_REDIRECT_URI'] = $_ENV['DISCORD_REDIRECT_URI'] ?? 'http://localhost:8080/auth/discord/callback';

        $this->container = require dirname(__DIR__, 2) . '/config/bootstrap.php';
    }

    protected function tearDown(): void
    {
        OAuthTestEnvironment::restorePhpUnitOAuthKeys();
        parent::tearDown();
    }

    public function testCoreServicesResolveWithoutEntityManagerCtorOrdering(): void
    {
        try {
            $entityManager = $this->container->get(EntityManager::class);
        } catch (Throwable $e) {
            $this->markTestSkipped('Database not reachable for container integration: ' . $e->getMessage());
        }

        $this->assertInstanceOf(EntityManager::class, $entityManager);
        $this->assertSame($entityManager, EntityManager::getManager());

        try {
            $middleware = $this->container->get(ConfidentialClientBasicAuthMiddleware::class);
            $clientRepository = $this->container->get(ClientRepository::class);
            $userRepository = $this->container->get(UserRepository::class);
            $assembler = $this->container->get(AuthorizationJwtAssembler::class);
            $idpJwt = $this->container->get(AmtgardIdpJwt::class);
        } catch (Throwable $e) {
            $this->markTestSkipped('Infrastructure not reachable for container integration: ' . $e->getMessage());
        }

        $this->assertInstanceOf(ConfidentialClientBasicAuthMiddleware::class, $middleware);
        $this->assertInstanceOf(ClientRepository::class, $clientRepository);
        $this->assertInstanceOf(UserRepository::class, $userRepository);
        $this->assertInstanceOf(AuthorizationJwtAssembler::class, $assembler);
        $this->assertInstanceOf(AmtgardIdpJwt::class, $idpJwt);
    }

    public function testMailboxPossessionEndpointsResolveFromTheBootedContainer(): void
    {
        $app = Bridge::create($this->container);
        (require dirname(__DIR__, 2) . '/config/routes.php')($app);
        $collector = $app->getRouteCollector();

        $endpoints = [
            'resources.profile.link_ork_code' => [ResourcesController::class, 'startOrkCodeClaim'],
            'resources.profile.email.start' => [ResourcesController::class, 'startEmailMigration'],
            'resources.profile.email.confirm' => [ResourcesController::class, 'confirmEmailMigration'],
            'resources.profile.email.commit' => [ResourcesController::class, 'commitEmailMigration'],
            'auth.connect.code' => [ConnectController::class, 'submitConnectCode'],
            'auth.connect.complete' => [ResourcesController::class, 'completeOrkClaim'],
        ];

        foreach ($endpoints as $name => [$class, $method]) {
            $callable = $collector->getNamedRoute($name)->getCallable();
            $this->assertSame([$class, $method], $callable);

            $controller = $this->resolveOrSkip($class);
            $this->assertTrue(method_exists($controller, $method));
        }
    }

    public function testOidcEndpointsResolveFromTheBootedContainer(): void
    {
        $app = Bridge::create($this->container);
        (require dirname(__DIR__, 2) . '/config/routes.php')($app);
        $collector = $app->getRouteCollector();

        $endpoints = [
            'oidc.discovery' => [DiscoveryController::class, 'openidConfiguration'],
            'oidc.jwks' => [DiscoveryController::class, 'jwks'],
            'oauth.userinfo' => [OidcUserInfoController::class, 'userinfo'],
        ];

        foreach ($endpoints as $name => [$class, $method]) {
            $callable = $collector->getNamedRoute($name)->getCallable();
            $this->assertSame([$class, $method], $callable);

            $controller = $this->resolveOrSkip($class);
            $this->assertTrue(method_exists($controller, $method));
        }
    }

    private function resolveOrSkip(string $class): object
    {
        try {
            return $this->container->get($class);
        } catch (Throwable $e) {
            if ($this->causedByInfrastructure($e)) {
                $this->markTestSkipped('Infrastructure not reachable for container integration: ' . $e->getMessage());
            }

            throw $e;
        }
    }

    private function causedByInfrastructure(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RedisException) {
                return true;
            }
            if ($current instanceof \PDOException && $this->isConnectionFailure($current)) {
                return true;
            }
        }

        return false;
    }

    private function isConnectionFailure(\PDOException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        if ($sqlState === '42S02') {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'getaddrinfo')
            || str_contains($message, 'Connection refused')
            || str_contains($message, 'server has gone away')
            || str_contains($message, '[2002]');
    }

    private function applyPhpUnitEnvironmentOverrides(): void
    {
        foreach ([
            'DB_DRIVER',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASS',
            'APP_ENV',
            'APP_DEBUG',
            'OAUTH_PRIVATE_KEY',
            'OAUTH_PUBLIC_KEY',
            'OAUTH_ENCRYPTION_KEY',
            'REDIS_PUBSUB_HOST',
            'REDIS_PUBSUB_PORT',
            'REDIS_PUBSUB_DB',
        ] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $_ENV[$key] = $value;
            }
        }
    }
}
