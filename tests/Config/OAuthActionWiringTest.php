<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Controllers\Server\OAuth\OAuthApproveAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthAuthorizeAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthFlowErrorRenderer;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthSessionAuthRequestStore;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthTokenAction;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Services\ResourcesUserinfoService;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * OAuth actions are Builder types. The container must set every property;
 * PHP-DI autowiring leaves them uninitialized and /oauth/authorize fatals
 * in the error handler.
 */
class OAuthActionWiringTest extends TestCase
{
    public function testAuthorizeActionFactoryInitializesErrorRenderer(): void
    {
        $errorRenderer = $this->errorRenderer();
        $container = $this->containerReturning([
            AuthorizationServer::class => $this->createStub(AuthorizationServer::class),
            ClientRepositoryInterface::class => $this->createStub(ClientRepositoryInterface::class),
            UserRepositoryInterface::class => $this->createStub(UserRepositoryInterface::class),
            UserClientAuthorizationRepository::class => $this->createStub(UserClientAuthorizationRepository::class),
            OAuthSessionAuthRequestStore::class => new OAuthSessionAuthRequestStore(),
            OAuthFlowErrorRenderer::class => $errorRenderer,
            LoggerInterface::class => $this->createStub(LoggerInterface::class),
            AmtgardIdpJwt::class => $this->createStub(AmtgardIdpJwt::class),
            RedisCacheRepository::class => $this->createStub(RedisCacheRepository::class),
        ]);

        $definitions = require __DIR__ . '/../../config/container.php';
        $action = $definitions[OAuthAuthorizeAction::class]($container);

        $this->assertInstanceOf(OAuthAuthorizeAction::class, $action);
        $this->assertSame($errorRenderer, $action->getErrorRenderer());
    }

    public function testTokenAndApproveFactoriesInitializeErrorRenderer(): void
    {
        $errorRenderer = $this->errorRenderer();
        $container = $this->containerReturning([
            AuthorizationServer::class => $this->createStub(AuthorizationServer::class),
            ClientRepositoryInterface::class => $this->createStub(ClientRepositoryInterface::class),
            UserClientAuthorizationRepository::class => $this->createStub(UserClientAuthorizationRepository::class),
            OAuthSessionAuthRequestStore::class => new OAuthSessionAuthRequestStore(),
            OAuthFlowErrorRenderer::class => $errorRenderer,
            TwigEnvironment::class => $this->createStub(TwigEnvironment::class),
        ]);

        $definitions = require __DIR__ . '/../../config/container.php';

        $token = $definitions[OAuthTokenAction::class]($container);
        $approve = $definitions[OAuthApproveAction::class]($container);

        $this->assertSame($errorRenderer, $token->getErrorRenderer());
        $this->assertSame($errorRenderer, $approve->getErrorRenderer());
    }

    public function testUserinfoServiceFactoryInitializesOrkProfileRepository(): void
    {
        $repository = $this->createStub(UserOrkProfileRepository::class);
        $container = $this->containerReturning([
            UserOrkProfileRepository::class => $repository,
        ]);

        $definitions = require __DIR__ . '/../../config/container.php';
        $service = $definitions[ResourcesUserinfoService::class]($container);

        $this->assertInstanceOf(ResourcesUserinfoService::class, $service);
        $this->assertSame($repository, $service->getOrkProfileRepository());
    }

    private function errorRenderer(): OAuthFlowErrorRenderer
    {
        return OAuthFlowErrorRenderer::builder()
            ->logger($this->createStub(LoggerInterface::class))
            ->view($this->createStub(TwigEnvironment::class))
            ->build();
    }

    /**
     * @param array<class-string, object> $services
     */
    private function containerReturning(array $services): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($services): object {
                $this->assertArrayHasKey($id, $services);

                return $services[$id];
            }
        );

        return $container;
    }
}
