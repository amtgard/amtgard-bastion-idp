<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Resource\ResourcesController;
use Amtgard\IdP\Controllers\Server\OAuth\OidcUserInfoController;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use DateTime;
use DI\Bridge\Slim\Bridge;
use DI\ContainerBuilder;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class OidcUserInfoControllerTest extends TestCase
{
    private const CACHE_CONTROL = 'no-store';
    private const USER_ID = '11111111-1111-1111-1111-111111111111';

    public function testGetWithAccessTokenAndEmailReturnsSubAndEmail(): void
    {
        $response = $this->handle(
            $this->bearerRequest('GET', 'access-token'),
            ['openid', 'email'],
            $this->user(),
        );

        $this->assertUserInfoOk($response, [
            'sub' => self::USER_ID,
            'email' => 'ada@example.com',
        ]);
        $this->assertArrayNotHasKey('name', $this->json($response));
        $this->assertArrayNotHasKey('preferred_username', $this->json($response));
        $this->assertArrayNotHasKey('updated_at', $this->json($response));
        $this->assertForbiddenIdentityKeys($this->json($response));
    }

    public function testGetWithOpenidAndProfileReturnsProfileClaims(): void
    {
        $updatedAt = (new DateTime())->setTimestamp(1_700_000_000);
        $response = $this->handle(
            $this->bearerRequest('GET', 'access-token'),
            ['openid', 'profile'],
            $this->user(updatedAt: $updatedAt),
        );

        $this->assertUserInfoOk($response, [
            'sub' => self::USER_ID,
            'name' => 'Ada Lovelace',
            'preferred_username' => 'ada',
            'updated_at' => 1_700_000_000,
        ]);
        $this->assertArrayNotHasKey('email', $this->json($response));
        $this->assertForbiddenIdentityKeys($this->json($response));
    }

    public function testProfileKeepsZeroUpdatedAt(): void
    {
        $response = $this->handle(
            $this->bearerRequest('GET', 'access-token'),
            ['openid', 'profile'],
            $this->user(firstName: '', lastName: '', username: '', updatedAt: (new DateTime())->setTimestamp(0)),
        );

        $this->assertUserInfoOk($response, [
            'sub' => self::USER_ID,
            'updated_at' => 0,
        ]);
    }

    public function testOauthUserIdIsCastToString(): void
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')
            ->willReturnCallback(function (ServerRequestInterface $request): ServerRequestInterface {
                return $request
                    ->withAttribute('oauth_user_id', new class {
                        public function __toString(): string
                        {
                            return '11111111-1111-1111-1111-111111111111';
                        }
                    })
                    ->withAttribute('oauth_scopes', ['openid']);
            });

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('findUserByUserId')
            ->with(self::USER_ID)
            ->willReturn($this->user());

        $response = (new OidcUserInfoController($resourceServer, $users))
            ->userinfo($this->bearerRequest('GET', 'access-token'), (new ResponseFactory())->createResponse());

        $this->assertUserInfoOk($response, ['sub' => self::USER_ID]);
    }

    public function testGetWithOpenidOnlyOmitsEmailAndProfile(): void
    {
        $response = $this->handle(
            $this->bearerRequest('GET', 'access-token'),
            ['openid'],
            $this->user(updatedAt: (new DateTime())->setTimestamp(1_700_000_000)),
        );

        $this->assertUserInfoOk($response, [
            'sub' => self::USER_ID,
        ]);
        $this->assertSame(['sub'], array_keys($this->json($response)));
    }

    public function testEmptyClaimKeysAreOmitted(): void
    {
        $response = $this->handle(
            $this->bearerRequest('GET', 'access-token'),
            ['openid', 'profile', 'email'],
            $this->user(email: '   ', firstName: '', lastName: '', username: null, updatedAt: null),
        );

        $this->assertUserInfoOk($response, [
            'sub' => self::USER_ID,
        ]);
        $this->assertSame(['sub'], array_keys($this->json($response)));
    }

    public function testPostWithHeaderAccessTokenSucceeds(): void
    {
        $response = $this->handle(
            $this->bearerRequest('POST', 'header-token'),
            ['openid', 'email'],
            $this->user(),
        );

        $this->assertUserInfoOk($response, [
            'sub' => self::USER_ID,
            'email' => 'ada@example.com',
        ]);
    }

    public function testPostFormAccessTokenWhenHeaderAbsent(): void
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->expects($this->once())
            ->method('validateAuthenticatedRequest')
            ->with($this->callback(function (ServerRequestInterface $request): bool {
                return $request->getHeaderLine('Authorization') === 'Bearer form-token';
            }))
            ->willReturnCallback(fn (ServerRequestInterface $request) => $this->validated($request, ['openid', 'email']));

        $users = $this->createMock(UserRepository::class);
        $users->method('findUserByUserId')->with(self::USER_ID)->willReturn($this->user());

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/oauth/userinfo')
            ->withParsedBody(['access_token' => 'form-token']);

        $response = (new OidcUserInfoController($resourceServer, $users))
            ->userinfo($request, (new ResponseFactory())->createResponse());

        $this->assertUserInfoOk($response, [
            'sub' => self::USER_ID,
            'email' => 'ada@example.com',
        ]);
    }

    public function testHeaderWinsWhenFormAccessTokenAlsoPresent(): void
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->expects($this->once())
            ->method('validateAuthenticatedRequest')
            ->with($this->callback(function (ServerRequestInterface $request): bool {
                return $request->getHeaderLine('Authorization') === 'Bearer header-token';
            }))
            ->willReturnCallback(fn (ServerRequestInterface $request) => $this->validated($request, ['openid']));

        $users = $this->createMock(UserRepository::class);
        $users->method('findUserByUserId')->willReturn($this->user());

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/oauth/userinfo')
            ->withHeader('Authorization', 'Bearer header-token')
            ->withParsedBody(['access_token' => 'form-token']);

        $response = (new OidcUserInfoController($resourceServer, $users))
            ->userinfo($request, (new ResponseFactory())->createResponse());

        $this->assertUserInfoOk($response, ['sub' => self::USER_ID]);
    }

    public function testGetIgnoresFormAccessToken(): void
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->expects($this->never())->method('validateAuthenticatedRequest');

        $response = (new OidcUserInfoController($resourceServer, $this->createStub(UserRepository::class)))
            ->userinfo(
                (new ServerRequestFactory())
                    ->createServerRequest('GET', '/oauth/userinfo')
                    ->withParsedBody(['access_token' => 'form-token']),
                (new ResponseFactory())->createResponse()
            );

        $this->assertUnauthorized($response);
    }

    public function testMissingOpenidReturns403InsufficientScope(): void
    {
        $response = $this->handle(
            $this->bearerRequest('GET', 'access-token'),
            ['profile', 'email'],
            $this->user(),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bearer error="insufficient_scope"', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame(self::CACHE_CONTROL, $response->getHeaderLine('Cache-Control'));
        $this->assertSame(['error' => 'insufficient_scope'], $this->json($response));
    }

    public function testNullScopesAreInsufficient(): void
    {
        $response = $this->handle(
            $this->bearerRequest('GET', 'access-token'),
            null,
            $this->user(),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bearer error="insufficient_scope"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testBlankAndNonStringScopesAreIgnored(): void
    {
        $response = $this->handle(
            $this->bearerRequest('GET', 'access-token'),
            ['', 1, 'profile'],
            $this->user(),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bearer error="insufficient_scope"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testNumericZeroScopeIsNotOpenidOrEmail(): void
    {
        $zeroIsNotOpenid = $this->handle(
            $this->bearerRequest('GET', 'access-token'),
            [0],
            $this->user(),
        );
        $zeroIsNotEmail = $this->handle(
            $this->bearerRequest('GET', 'access-token'),
            ['openid', 0],
            $this->user(),
        );

        $this->assertSame(403, $zeroIsNotOpenid->getStatusCode());
        $this->assertUserInfoOk($zeroIsNotEmail, ['sub' => self::USER_ID]);
    }

    public function testAuthorizationJwtReturns401(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $jwt = FirebaseJwtTestFactory::authorizationForUserAndClient(
            self::USER_ID,
            'oidc-test-client',
            null,
            '{"Statement":[]}'
        );

        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')
            ->willReturnCallback(fn (ServerRequestInterface $request) => $this->validated($request, ['profile']));

        $response = (new OidcUserInfoController($resourceServer, $this->createStub(UserRepository::class)))
            ->userinfo($this->bearerRequest('GET', $jwt), (new ResponseFactory())->createResponse());

        $this->assertUnauthorized($response);
    }

    public function testBearerTokenIsTrimmedBeforeAuthorizationJwtCheck(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $jwt = FirebaseJwtTestFactory::authorizationForUserAndClient(
            self::USER_ID,
            'oidc-test-client',
            null,
            '{"Statement":[]}'
        );

        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->expects($this->never())->method('validateAuthenticatedRequest');

        $response = (new OidcUserInfoController($resourceServer, $this->createStub(UserRepository::class)))
            ->userinfo($this->bearerRequest('GET', $jwt . '   '), (new ResponseFactory())->createResponse());

        $this->assertUnauthorized($response);
    }

    public function testUnsignedPolicyJwtIsNotTreatedAsAuthorizationJwt(): void
    {
        $unsigned = $this->unsignedJwt(['aud' => 'oidc-test-client', 'policy' => '{"Statement":[]}']);
        $response = $this->handle(
            $this->bearerRequest('GET', $unsigned),
            ['openid'],
            $this->user(),
        );

        $this->assertUserInfoOk($response, ['sub' => self::USER_ID]);
    }

    public function testSignedAccessTokenJwtIsNotRejectedAsAuthorizationJwt(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $accessJwt = FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient(self::USER_ID, 'oidc-test-client');

        $response = $this->handle(
            $this->bearerRequest('GET', $accessJwt),
            ['openid', 'email'],
            $this->user(),
        );

        $this->assertUserInfoOk($response, [
            'sub' => self::USER_ID,
            'email' => 'ada@example.com',
        ]);
    }

    public function testAuthorizationJwtInFormReturns401(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $jwt = FirebaseJwtTestFactory::authorizationForUserAndClient(
            self::USER_ID,
            'oidc-test-client',
            null,
            '{"Statement":[]}'
        );

        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')
            ->willReturnCallback(fn (ServerRequestInterface $request) => $this->validated($request, ['profile']));

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/oauth/userinfo')
            ->withParsedBody(['access_token' => $jwt]);

        $response = (new OidcUserInfoController($resourceServer, $this->createStub(UserRepository::class)))
            ->userinfo($request, (new ResponseFactory())->createResponse());

        $this->assertUnauthorized($response);
    }

    public function testMissingTokenReturns401(): void
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->expects($this->never())->method('validateAuthenticatedRequest');

        $response = (new OidcUserInfoController($resourceServer, $this->createStub(UserRepository::class)))
            ->userinfo(
                (new ServerRequestFactory())->createServerRequest('GET', '/oauth/userinfo'),
                (new ResponseFactory())->createResponse()
            );

        $this->assertUnauthorized($response);
    }

    public function testAuthorizationHeaderPresentButNotBearerReturns401(): void
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->expects($this->never())->method('validateAuthenticatedRequest');

        $response = (new OidcUserInfoController($resourceServer, $this->createStub(UserRepository::class)))
            ->userinfo(
                (new ServerRequestFactory())
                    ->createServerRequest('POST', '/oauth/userinfo')
                    ->withHeader('Authorization', 'Basic abc')
                    ->withParsedBody(['access_token' => 'form-token']),
                (new ResponseFactory())->createResponse()
            );

        $this->assertUnauthorized($response);
    }

    public function testEmptyBearerAndEmptyFormTokenReturn401(): void
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->expects($this->never())->method('validateAuthenticatedRequest');
        $controller = new OidcUserInfoController($resourceServer, $this->createStub(UserRepository::class));

        $emptyBearer = $controller->userinfo(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/oauth/userinfo')
                ->withHeader('Authorization', 'Bearer    '),
            (new ResponseFactory())->createResponse()
        );
        $emptyForm = $controller->userinfo(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/oauth/userinfo')
                ->withParsedBody(['access_token' => '   ']),
            (new ResponseFactory())->createResponse()
        );
        $nonStringForm = $controller->userinfo(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/oauth/userinfo')
                ->withParsedBody(['access_token' => ['nested']]),
            (new ResponseFactory())->createResponse()
        );
        $nonArrayBody = $controller->userinfo(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/oauth/userinfo')
                ->withParsedBody((object) ['access_token' => 'form-token']),
            (new ResponseFactory())->createResponse()
        );

        $this->assertUnauthorized($emptyBearer);
        $this->assertUnauthorized($emptyForm);
        $this->assertUnauthorized($nonStringForm);
        $this->assertUnauthorized($nonArrayBody);
    }

    public function testInvalidAccessTokenReturns401(): void
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')
            ->willThrowException(OAuthServerException::accessDenied('bad token'));

        $response = (new OidcUserInfoController($resourceServer, $this->createStub(UserRepository::class)))
            ->userinfo($this->bearerRequest('GET', 'access-token'), (new ResponseFactory())->createResponse());

        $this->assertUnauthorized($response);
    }

    public function testUnknownUserReturns401(): void
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')
            ->willReturnCallback(fn (ServerRequestInterface $request) => $this->validated($request, ['openid']));

        $users = $this->createMock(UserRepository::class);
        $users->method('findUserByUserId')->with(self::USER_ID)->willReturn(null);

        $response = (new OidcUserInfoController($resourceServer, $users))
            ->userinfo($this->bearerRequest('GET', 'access-token'), (new ResponseFactory())->createResponse());

        $this->assertUnauthorized($response);
    }

    public function testOauthUserinfoRoutesArePublicGetAndPost(): void
    {
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/config/routes.php')($app);
        $route = $app->getRouteCollector()->getNamedRoute('oauth.userinfo');

        $this->assertSame(['GET', 'POST'], $route->getMethods());
        $this->assertSame('/oauth/userinfo', $route->getPattern());
        $this->assertSame([OidcUserInfoController::class, 'userinfo'], $route->getCallable());
    }

    public function testResourcesUserinfoRouteIsUnchanged(): void
    {
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/config/routes.php')($app);
        $route = $app->getRouteCollector()->getNamedRoute('resources.userinfo');

        $this->assertSame(['GET'], $route->getMethods());
        $this->assertSame('/resources/userinfo', $route->getPattern());
        $this->assertSame([ResourcesController::class, 'userInfo'], $route->getCallable());
    }

    public function testSlimDispatchesGetAndPostUserinfo(): void
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')
            ->willReturnCallback(fn (ServerRequestInterface $request) => $this->validated($request, ['openid', 'email']));
        $users = $this->createMock(UserRepository::class);
        $users->method('findUserByUserId')->willReturn($this->user());

        $builder = new ContainerBuilder();
        $builder->useAutowiring(false);
        $builder->addDefinitions([
            OidcUserInfoController::class => new OidcUserInfoController($resourceServer, $users),
        ]);
        $app = Bridge::create($builder->build());
        (require dirname(__DIR__, 2) . '/config/routes.php')($app);

        $get = $app->handle($this->bearerRequest('GET', 'access-token'));
        $post = $app->handle($this->bearerRequest('POST', 'access-token'));

        $this->assertUserInfoOk($get, [
            'sub' => self::USER_ID,
            'email' => 'ada@example.com',
        ]);
        $this->assertUserInfoOk($post, [
            'sub' => self::USER_ID,
            'email' => 'ada@example.com',
        ]);
    }

    /**
     * @param list<mixed>|null $scopes
     */
    private function handle(ServerRequestInterface $request, ?array $scopes, ?UserEntity $user): ResponseInterface
    {
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')
            ->willReturnCallback(fn (ServerRequestInterface $incoming) => $this->validated($incoming, $scopes));

        $users = $this->createMock(UserRepository::class);
        $users->method('findUserByUserId')->willReturn($user);

        return (new OidcUserInfoController($resourceServer, $users))
            ->userinfo($request, (new ResponseFactory())->createResponse());
    }

    /**
     * @param list<mixed>|null $scopes
     */
    private function validated(ServerRequestInterface $request, ?array $scopes): ServerRequestInterface
    {
        return $request
            ->withAttribute('oauth_user_id', self::USER_ID)
            ->withAttribute('oauth_scopes', $scopes);
    }

    private function bearerRequest(string $method, string $token): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/oauth/userinfo')
            ->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function unsignedJwt(array $payload): string
    {
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return $encode(json_encode(['alg' => 'none', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            . '.'
            . $encode(json_encode($payload, JSON_THROW_ON_ERROR))
            . '.sig';
    }

    /**
     * @param array<string, int|string> $expected
     */
    private function assertUserInfoOk(ResponseInterface $response, array $expected): void
    {
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(self::CACHE_CONTROL, $response->getHeaderLine('Cache-Control'));
        $this->assertSame($expected, $this->json($response));
    }

    private function assertUnauthorized(ResponseInterface $response): void
    {
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame(self::CACHE_CONTROL, $response->getHeaderLine('Cache-Control'));
        $this->assertSame(['error' => 'invalid_token'], $this->json($response));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertForbiddenIdentityKeys(array $body): void
    {
        foreach (['aud', 'iss', 'exp', 'nonce', 'email_verified', 'picture', 'orkid', 'policy', 'pvh'] as $key) {
            $this->assertArrayNotHasKey($key, $body);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function user(
        string $userId = self::USER_ID,
        ?string $email = 'ada@example.com',
        ?string $firstName = 'Ada',
        ?string $lastName = 'Lovelace',
        ?string $username = 'ada',
        ?DateTime $updatedAt = null,
    ): UserEntity {
        return new class($userId, $email, $firstName, $lastName, $username, $updatedAt) extends UserEntity {
            public function __construct(
                private ?string $testUserId,
                private ?string $testEmail,
                private ?string $testFirstName,
                private ?string $testLastName,
                private ?string $testUsername,
                private ?DateTime $testUpdatedAt,
            ) {
            }

            public function getUserId(): ?string
            {
                return $this->testUserId;
            }

            public function getEmail(): ?string
            {
                return $this->testEmail;
            }

            public function getFirstName(): ?string
            {
                return $this->testFirstName;
            }

            public function getLastName(): ?string
            {
                return $this->testLastName;
            }

            public function getUsername(): ?string
            {
                return $this->testUsername;
            }

            public function getUpdatedAt(): ?DateTime
            {
                return $this->testUpdatedAt;
            }
        };
    }
}
