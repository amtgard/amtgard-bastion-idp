<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\IdP\Middleware\ConfidentialClientAuthMiddleware;
use Amtgard\IdP\Middleware\ConfidentialClientCredentialMiddleware;
use Amtgard\IdP\Middleware\CsrfMiddleware;
use Amtgard\IdP\Middleware\LocalAdminUserMiddleware;
use Amtgard\IdP\Middleware\LocalIdpAuthMiddleware;
use Amtgard\IdP\Middleware\ManagementMiddleware;
use Amtgard\IdP\Middleware\OAuthAccessTokenElevationMiddleware;
use Amtgard\IdP\Models\AllowedLinkOrkProfileClientIds;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\Security\AllowListedConfidentialClientAuthenticator;
use Amtgard\IdP\Utility\Security\ConfidentialClientAuthMode;
use Amtgard\IdP\Utility\Security\ConfidentialClientAuthenticator;
use Amtgard\IdP\Utility\UserAuthority;
use Amtgard\ActiveRecordOrm\EntityManager;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Uri;
use Slim\Routing\RouteContext;

final class M2AuthMiddlewareLoggingTest extends TestCase
{
    private ?string $oldManagementKey = null;

    protected function setUp(): void
    {
        $this->oldManagementKey = $_ENV['MANAGEMENT_KEY'] ?? null;
        @session_start();
        $_SESSION = [];
        FirebaseJwtTestFactory::ensureKeys();
    }

    protected function tearDown(): void
    {
        if ($this->oldManagementKey !== null) {
            $_ENV['MANAGEMENT_KEY'] = $this->oldManagementKey;
        } else {
            unset($_ENV['MANAGEMENT_KEY']);
        }
    }

    public function testManagementMiddlewareLogsMisconfigurationAndMismatch(): void
    {
        unset($_ENV['MANAGEMENT_KEY']);
        $capture = CapturedIdpLogging::create();
        $middleware = new ManagementMiddleware($capture->idpLogging);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getUri')->willReturn(new Uri('http', 'localhost', null, '/management/clients'));
        $handler = $this->createMock(RequestHandlerInterface::class);

        $middleware->process($request, $handler);
        $this->assertTrue($capture->handler('app')->hasEmergencyRecords());
        $this->assertSame('management_key_missing', $capture->handler('app')->getRecords()[0]->context['reason']);

        $_ENV['MANAGEMENT_KEY'] = 'short';
        $middleware->process($request, $handler);
        $this->assertSame('management_key_too_short', $capture->handler('app')->getRecords()[1]->context['reason']);

        $_ENV['MANAGEMENT_KEY'] = str_repeat('a', 32);
        $request->method('getQueryParams')->willReturn(['key' => 'wrong']);
        $middleware->process($request, $handler);
        $this->assertTrue($capture->handler('security')->hasAlertRecords());
        $this->assertSame('management_key_mismatch', $capture->handler('security')->getRecords()[0]->context['reason']);
    }

    public function testManagementMiddlewareLogsAuditOnSuccess(): void
    {
        $key = str_repeat('b', 32);
        $_ENV['MANAGEMENT_KEY'] = $key;
        $capture = CapturedIdpLogging::create();
        $middleware = new ManagementMiddleware($capture->idpLogging);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['key' => $key]);
        $request->method('getUri')->willReturn(new Uri('http', 'localhost', null, '/management/health'));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->createMock(ResponseInterface::class));

        $middleware->process($request, $handler);
        $this->assertTrue($capture->handler('audit')->hasInfoRecords());
        $this->assertSame('management_access', $capture->handler('audit')->getRecords()[0]->context['audit_action']);
    }

    public function testLocalAdminUserMiddlewareLogsAllowDenyAndUnauthenticated(): void
    {
        $capture = CapturedIdpLogging::create();
        $userRepository = $this->createMock(UserRepository::class);
        $userAuthority = $this->createMock(UserAuthority::class);
        $middleware = new LocalAdminUserMiddleware($userRepository, $userAuthority, $capture->idpLogging);

        $user = $this->createMock(\Amtgard\IdP\Persistence\Client\Entities\UserEntity::class);
        $routeParser = $this->createMock(RouteParserInterface::class);
        $routeParser->method('urlFor')->willReturn('/resources/profile');
        $routingResults = $this->createMock(\Slim\Routing\RoutingResults::class);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnMap([
            ['session', null, ['user_id' => 'admin-1']],
            [RouteContext::ROUTING_RESULTS, null, $routingResults],
            [RouteContext::ROUTE_PARSER, null, $routeParser],
        ]);
        $userRepository->method('findUserByUserId')->willReturn($user);
        $userAuthority->method('isAdmin')->willReturn(true);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->createMock(ResponseInterface::class));
        $middleware->process($request, $handler);
        $this->assertTrue($capture->handler('security')->hasDebugRecords());

        $userAuthority = $this->createMock(UserAuthority::class);
        $userAuthority->method('isAdmin')->willReturn(false);
        $middleware = new LocalAdminUserMiddleware($userRepository, $userAuthority, $capture->idpLogging);
        $middleware->process($request, $handler);
        $this->assertSame('not_admin', $capture->handler('security')->getRecords()[1]->context['failure_reason']);

        $request2 = $this->createMock(ServerRequestInterface::class);
        $request2->method('getAttribute')->willReturnMap([
            ['session', null, []],
            [RouteContext::ROUTING_RESULTS, null, $routingResults],
            [RouteContext::ROUTE_PARSER, null, $routeParser],
        ]);
        $middleware->process($request2, $handler);
        $this->assertSame('unauthenticated', $capture->handler('security')->getRecords()[2]->context['failure_reason']);
    }

    public function testLocalIdpAuthMiddlewareLogsSecurityAndOAuthBranches(): void
    {
        $capture = CapturedIdpLogging::create();
        $authorized = AuthorizedClients::builder()->clientIds(['valid-client'])->build();
        $resourceServer = $this->createMock(ResourceServer::class);
        $middleware = new LocalIdpAuthMiddleware(
            $this->createMock(EntityManager::class),
            $capture->idpLogging,
            $authorized,
            $resourceServer,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer tok');
        try {
            $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame('bearer_not_allowed', $capture->handler('security')->getRecords()[0]->context['failure_reason']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'bad']);
        try {
            $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame('client_not_authorized', $capture->handler('security')->getRecords()[1]->context['failure_reason']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client', 'user_id' => 'u1']);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->createMock(ResponseInterface::class));
        $middleware->process($request, $handler);
        $this->assertSame('u1', $capture->handler('security')->getRecords()[2]->context['user_uuid']);

        $validated = $this->createMock(ServerRequestInterface::class);
        $validated->method('getAttribute')->willReturnMap([
            ['oauth_user_id', null, 'ou1'],
            ['oauth_client_id', null, 'oc1'],
        ]);
        $resourceServer->method('validateAuthenticatedRequest')->willReturn($validated);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client']);
        $middleware->process($request, $handler);
        $this->assertSame('ou1', $capture->handler('oauth')->getRecords()[0]->context['oauth_user_id']);

        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')->willThrowException(
            OAuthServerException::accessDenied('hint-text')
        );
        $middleware = new LocalIdpAuthMiddleware(
            $this->createMock(EntityManager::class),
            $capture->idpLogging,
            $authorized,
            $resourceServer,
        );
        $routeParser = $this->createMock(RouteParserInterface::class);
        $routeParser->method('urlFor')->willReturn('/auth/login');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->willReturnMap([
            ['session', null, ['client_id' => 'valid-client']],
            [RouteContext::ROUTING_RESULTS, null, $this->createMock(\Slim\Routing\RoutingResults::class)],
            [RouteContext::ROUTE_PARSER, null, $routeParser],
        ]);
        $request->method('getUri')->willReturn(new Uri('http', 'localhost', null, '/resources/profile'));
        $middleware->process($request, $handler);
        $this->assertTrue($capture->handler('oauth')->hasWarningRecords());
        $this->assertTrue($capture->handler('app')->hasNoticeRecords());
    }

    public function testCsrfMiddlewareLogsFailureReason(): void
    {
        $capture = CapturedIdpLogging::create();
        $middleware = new CsrfMiddleware($capture->idpLogging);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getHeaderLine')->with('X-CSRF-Token')->willReturn('bad');
        $request->method('getUri')->willReturn(new Uri('http', 'localhost', null, '/management/clients'));

        try {
            $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpForbiddenException) {
        }

        $record = $capture->handler('security')->getRecords()[0];
        $this->assertSame('csrf', $record->context['failure_reason']);
        $this->assertSame('/management/clients', $record->context['route']);
    }

    public function testOAuthAccessTokenElevationMiddlewareLogsRejectionsAndSession(): void
    {
        $capture = CapturedIdpLogging::create();
        $authorized = AuthorizedClients::builder()->clientIds(['valid-client'])->build();
        $middleware = new OAuthAccessTokenElevationMiddleware(
            $capture->idpLogging,
            $authorized,
            $this->createMock(ResourceServer::class),
        );

        $jwt = $this->generateAuthorizationJwt('u1', 'valid-client');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer ' . $jwt);
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client']);
        try {
            $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame('authorization_jwt_on_elevation', $capture->handler('security')->getRecords()[0]->context['failure_reason']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'bad', 'user_id' => 'u1']);
        try {
            $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame('client_not_authorized', $capture->handler('security')->getRecords()[1]->context['failure_reason']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client', 'user_id' => 'u1']);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->createMock(ResponseInterface::class));
        $middleware->process($request, $handler);
        $this->assertSame('u1', $capture->handler('security')->getRecords()[2]->context['user_uuid']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client']);
        try {
            $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame('missing_access_token', $capture->handler('security')->getRecords()[3]->context['failure_reason']);

        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')->willThrowException(
            OAuthServerException::accessDenied('bad')
        );
        $middleware = new OAuthAccessTokenElevationMiddleware(
            $capture->idpLogging,
            $authorized,
            $resourceServer,
        );
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer access-token');
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client']);
        try {
            $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame('invalid_access_token', $capture->handler('security')->getRecords()[4]->context['failure_reason']);
        $this->assertSame(Level::Error, $capture->handler('security')->getRecords()[4]->level);
    }

    public function testConfidentialClientAuthenticatorLogsFailureReasons(): void
    {
        $capture = CapturedIdpLogging::create();
        $repo = $this->createMock(ClientRepository::class);
        $authenticator = new ConfidentialClientAuthenticator($repo, $capture->idpLogging);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');

        try {
            $authenticator->authenticate($request, ConfidentialClientAuthMode::CredentialsOnly);
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame('missing_basic', $capture->handler('security')->getRecords()[0]->context['failure_reason']);

        $capture2 = CapturedIdpLogging::create();
        $repo2 = $this->createMock(ClientRepository::class);
        $repo2->method('validateClient')->willReturn(false);
        $request2 = $this->createMock(ServerRequestInterface::class);
        $request2->method('getHeaderLine')->willReturn('Basic ' . base64_encode('app:wrong'));
        $authenticator2 = new ConfidentialClientAuthenticator($repo2, $capture2->idpLogging);
        try {
            $authenticator2->authenticate($request2, ConfidentialClientAuthMode::CredentialsOnly);
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame('invalid_credentials', $capture2->handler('security')->getRecords()[0]->context['failure_reason']);
    }

    public function testAllowListedAuthenticatorUsesErrorAndAlert(): void
    {
        $_ENV['LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS'] = 'ork-client';
        $capture = CapturedIdpLogging::create();
        $repo = $this->createMock(ClientRepository::class);
        $authenticator = new AllowListedConfidentialClientAuthenticator(
            $repo,
            new AllowedLinkOrkProfileClientIds(),
            $capture->idpLogging,
        );
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        try {
            $authenticator->authenticate($request);
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame(Level::Error, $capture->handler('security')->getRecords()[0]->level);

        $capture2 = CapturedIdpLogging::create();
        $authenticator2 = new AllowListedConfidentialClientAuthenticator(
            $repo,
            new AllowedLinkOrkProfileClientIds(),
            $capture2->idpLogging,
        );
        $request2 = $this->createMock(ServerRequestInterface::class);
        $request2->method('getHeaderLine')->willReturn('Basic ' . base64_encode('other:secret'));
        try {
            $authenticator2->authenticate($request2);
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame(Level::Alert, $capture2->handler('security')->getRecords()[0]->level);
    }

    public function testConfidentialClientMiddlewareLogsAcceptedClients(): void
    {
        $capture = CapturedIdpLogging::create();
        $client = new class extends Client {
            public function getIsConfidential(): bool { return true; }
            public function getIamService(): ?string { return 'Skbc'; }
            public function getIdentifier(): string { return 'app-client'; }
        };
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('validateClient')->willReturn(true);
        $repo->method('findClientByIdentifier')->willReturn($client);
        $authenticator = new ConfidentialClientAuthenticator($repo, $capture->idpLogging);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Basic ' . base64_encode('app:secret'));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->createMock(ResponseInterface::class));

        $iamMiddleware = new ConfidentialClientAuthMiddleware($authenticator, $capture->idpLogging);
        $iamMiddleware->process($request, $handler);
        $this->assertSame('Skbc', $capture->handler('security')->getRecords()[1]->context['iam_service']);

        $credentialMiddleware = new ConfidentialClientCredentialMiddleware($authenticator, $capture->idpLogging);
        $credentialMiddleware->process($request, $handler);
        $this->assertSame('app-client', $capture->handler('security')->getRecords()[3]->context['client_id']);
    }

    private function generateAuthorizationJwt(string $userId, string $clientId): string
    {
        $pvh = Pvh::encode(
            1_700_000_000_000,
            Pvh::policyHash($clientId, '[]', '')
        );

        return FirebaseJwtTestFactory::authorizationForUserAndClient($userId, $clientId, $pvh);
    }
}
