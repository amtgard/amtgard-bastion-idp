<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Middleware\OAuthAccessTokenElevationMiddleware;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\AuthorizedClients;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

class OAuthAccessTokenElevationMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];

        FirebaseJwtTestFactory::ensureKeys();
    }

    public function testProcessAllowsAuthenticatedSession(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');
        $request->method('getAttribute')->with('session')->willReturn([
            'client_id' => 'valid-client',
            'user_id' => 'user-123',
        ]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects($this->once())->method('handle')->with($request)->willReturn($response);

        $middleware = $this->makeMiddleware();
        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function testProcessRejectsUnauthorizedClientInSession(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->with('session')->willReturn([
            'client_id' => 'bad-client',
        ]);

        $this->expectException(HttpUnauthorizedException::class);
        $this->makeMiddleware()->process($request, $this->createMock(RequestHandlerInterface::class));
    }

    public function testProcessElevatesValidBearerAccessToken(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer access-token');
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client']);

        $validated = $this->createMock(ServerRequestInterface::class);
        $validated->method('getAttribute')->willReturnCallback(function (string $name) {
            return match ($name) {
                'oauth_user_id' => 'uuid-user',
                'oauth_client_id' => 'valid-client',
                default => null,
            };
        });

        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')->willReturn($validated);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->with($validated)->willReturn($this->createMock(ResponseInterface::class));

        $this->makeMiddleware($resourceServer)->process($request, $handler);

        $this->assertSame('uuid-user', $_SESSION['user_id']);
        $this->assertSame('valid-client', $_SESSION['client_id']);
    }

    public function testProcessRejectsInvalidBearerAccessToken(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer bad-token');
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client']);

        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')
            ->willThrowException(OAuthServerException::accessDenied());

        $this->expectException(HttpUnauthorizedException::class);
        $this->makeMiddleware($resourceServer)->process($request, $this->createMock(RequestHandlerInterface::class));
    }

    public function testProcessRejectsAuthorizationJwtBearer(): void
    {
        $jwt = $this->generateAuthorizationJwt('user-123', 'valid-client');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn("Bearer {$jwt}");
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client']);

        $this->expectException(HttpUnauthorizedException::class);
        $this->makeMiddleware()->process($request, $this->createMock(RequestHandlerInterface::class));
    }

    public function testProcessElevatesOAuthAccessTokenJwtViaResourceServer(): void
    {
        $jwt = $this->generateOAuthAccessTokenJwt('uuid-user', 'valid-client');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn("Bearer {$jwt}");
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client']);

        $validated = $this->createMock(ServerRequestInterface::class);
        $validated->method('getAttribute')->willReturnCallback(function (string $name) {
            return match ($name) {
                'oauth_user_id' => 'uuid-user',
                'oauth_client_id' => 'valid-client',
                default => null,
            };
        });

        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->expects($this->once())
            ->method('validateAuthenticatedRequest')
            ->with($request)
            ->willReturn($validated);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->with($validated)->willReturn($this->createMock(ResponseInterface::class));

        $this->makeMiddleware($resourceServer)->process($request, $handler);

        $this->assertSame('uuid-user', $_SESSION['user_id']);
        $this->assertSame('valid-client', $_SESSION['client_id']);
    }

    public function testProcessRequiresBearerWhenSessionHasNoUserId(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');
        $request->method('getAttribute')->with('session')->willReturn(['client_id' => 'valid-client']);

        $this->expectException(HttpUnauthorizedException::class);
        $this->makeMiddleware()->process($request, $this->createMock(RequestHandlerInterface::class));
    }

    public function testProcessRejectsMissingClientIdInSession(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->with('session')->willReturn([]);

        $this->expectException(HttpUnauthorizedException::class);
        $this->makeMiddleware()->process($request, $this->createMock(RequestHandlerInterface::class));
    }

    private function makeMiddleware(?ResourceServer $resourceServer = null): OAuthAccessTokenElevationMiddleware
    {
        return new OAuthAccessTokenElevationMiddleware(
            $this->createMock(EntityManager::class),
            $this->createMock(LoggerInterface::class),
            AuthorizedClients::builder()->clientIds(['valid-client'])->build(),
            $resourceServer ?? $this->createMock(ResourceServer::class),
        );
    }

    private function generateAuthorizationJwt(string $userId, string $clientId): string
    {
        $pvh = Pvh::encode(
            1_700_000_000_000,
            Pvh::policyHash($clientId, '[]', '')
        );

        return FirebaseJwtTestFactory::authorizationForUserAndClient($userId, $clientId, $pvh);
    }

    private function generateOAuthAccessTokenJwt(string $userId, string $clientId): string
    {
        return FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient($userId, $clientId);
    }
}
