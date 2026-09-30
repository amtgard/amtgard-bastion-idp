<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Controllers\Resource\ClientIamRequestInterpreter;
use Amtgard\IdP\Controllers\Resource\ClientPolicyClaimsController;
use Amtgard\IdP\Controllers\Resource\ClientUserMetadataController;
use Amtgard\IdP\Middleware\ConfidentialClientAuthMiddleware;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Services\ClientIamMetadataService;
use Amtgard\IdP\Services\ClientIamPolicyService;
use Amtgard\IdP\Utility\Client\ClientResourcesRequestResolver;
use DI\Bridge\Slim\Bridge;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Slim binds {idp_user_id} by parameter name. A direct method call never checks that.
 */
final class ClientIamRouteInvocationTest extends TestCase
{
    public function testPolicyClaimListReceivesTheRouteUserId(): void
    {
        $user = $this->createStub(UserEntity::class);
        $users = $this->createStub(UserRepository::class);
        $users->method('findUserByUserId')->with('uuid-123')->willReturn($user);
        $policy = $this->createMock(ClientIamPolicyService::class);
        $policy->expects($this->once())->method('listClaims')->with($this->isInstanceOf(Client::class), $user)->willReturn([]);

        $container = $this->container($this->createStub(Client::class));
        $container->set(ClientPolicyClaimsController::class, new ClientPolicyClaimsController(
            new NullLogger(),
            new ClientIamRequestInterpreter(new ClientResourcesRequestResolver(
                $users,
                $this->createStub(UserLoginRepository::class),
            )),
            $policy,
        ));

        $response = $this->app($container)->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://localhost/resources/client/policy-claims/uuid-123'
            )
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"claims"', (string) $response->getBody());
    }

    public function testMetadataGetReceivesTheRouteUserId(): void
    {
        $user = $this->createStub(UserEntity::class);
        $users = $this->createStub(UserRepository::class);
        $users->method('findUserByUserId')->willReturn($user);

        $container = $this->container($this->createStub(Client::class));
        $container->set(ClientUserMetadataController::class, new ClientUserMetadataController(
            new ClientIamRequestInterpreter(new ClientResourcesRequestResolver(
                $users,
                $this->createStub(UserLoginRepository::class),
            )),
            $this->createStub(ClientIamMetadataService::class),
        ));

        $response = $this->app($container)->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://localhost/resources/client/user-metadata/uuid-123'
            )
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('login_id is required', (string) $response->getBody());
    }

    private function container(Client $client): Container
    {
        $container = new Container();
        $container->set(ConfidentialClientAuthMiddleware::class, new class($client) implements MiddlewareInterface {
            public function __construct(private Client $client) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute(
                    ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE,
                    $this->client
                ));
            }
        });

        return $container;
    }

    private function app(Container $container): \Slim\App
    {
        $app = Bridge::create($container);
        (require dirname(__DIR__, 2) . '/config/routes.php')($app);

        return $app;
    }
}
