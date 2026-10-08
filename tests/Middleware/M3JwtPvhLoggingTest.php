<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Middleware\CachedJwtLocalIdpAuthMiddleware;
use Amtgard\IdP\Middleware\ClientRestrictedAuthMiddleware;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Log\RequestLogContext;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\Pvh\PvhAuthorizationGate;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\Security\OAuthAccessTokenFallback;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpUnauthorizedException;

final class M3JwtPvhLoggingTest extends TestCase
{
    private const USER = 'user-123';
    private const CLIENT = 'valid-client';
    private const EMAIL = 'test@example.com';
    private const POLICY = '[]';

    protected function setUp(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        @session_start();
        $_SESSION = [];
        RequestLogContext::reset();
    }

    protected function tearDown(): void
    {
        RequestLogContext::reset();
    }

    public function testCachedJwtMiddlewareLogsJwtValidationFailures(): void
    {
        $capture = CapturedIdpLogging::create();
        $middleware = $this->cachedJwtMiddleware($capture);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        try {
            $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame(
            'jwt_required',
            $this->failureReason($capture, 'cached jwt local idp auth rejected')
        );

        $jwt = FirebaseJwtTestFactory::encode(['aud' => self::CLIENT, 'exp' => time() + 3600]);
        $request2 = $this->createMock(ServerRequestInterface::class);
        $request2->method('getHeaderLine')->willReturn("Bearer {$jwt}");
        try {
            $middleware->process($request2, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame(
            'missing_sub',
            $this->failureReason($capture, 'cached jwt local idp auth rejected', 1)
        );
    }

    public function testCachedJwtMiddlewareLogsPvhRejectionsAndSetsIdentity(): void
    {
        $capture = CapturedIdpLogging::create();
        $redis = $this->createMock(RedisCacheRepository::class);
        $current = $this->samplePvh(1_700_000_000_001);
        $prev = $this->samplePvh(1_700_000_000_000);
        $redis->method('getPvhRecord')->willReturn(
            new PvhCacheRecord(self::USER, self::CLIENT, self::EMAIL, $current, $prev)
        );
        $middleware = $this->cachedJwtMiddleware($capture, $redis);

        $jwt = FirebaseJwtTestFactory::authorizationForUserAndClient(self::USER, self::CLIENT, $prev);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn("Bearer {$jwt}");
        $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        $this->assertSame('StaleToken', $capture->handler('security')->getRecords()[1]->context['outcome']);
        $this->assertSame(Level::Error, $capture->handler('security')->getRecords()[1]->level);
        $this->assertSame(self::USER, RequestLogContext::userUuid());
    }

    public function testClientRestrictedMiddlewareLogsSessionBypassAndFailures(): void
    {
        $capture = CapturedIdpLogging::create();
        $_SESSION['client_id'] = self::CLIENT;
        $middleware = $this->clientRestrictedMiddleware($capture);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->createMock(ResponseInterface::class));
        $request = $this->createMock(ServerRequestInterface::class);
        $middleware->process($request, $handler);
        $this->assertSame(self::CLIENT, $capture->handler('security')->getRecords()[0]->context['client_id']);

        $_SESSION['client_id'] = 'invalid-client';
        $request2 = $this->createMock(ServerRequestInterface::class);
        $request2->method('getHeaderLine')->willReturn('');
        try {
            $middleware->process($request2, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame(
            'jwt_invalid',
            $this->failureReason($capture, 'client restricted auth rejected')
        );
    }

    public function testClientRestrictedMiddlewareLogsClientNotRestricted(): void
    {
        $capture = CapturedIdpLogging::create();
        $_SESSION['client_id'] = 'invalid-client';
        $jwt = FirebaseJwtTestFactory::authorizationForUserAndClient(
            self::USER,
            'other-client',
            $this->samplePvh()
        );
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn("Bearer {$jwt}");
        $middleware = $this->clientRestrictedMiddleware($capture);
        try {
            $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame('client_not_restricted', $capture->handler('security')->getRecords()[0]->context['failure_reason']);
    }

    public function testOAuthAccessTokenFallbackLogsRejectAndAccept(): void
    {
        $capture = CapturedIdpLogging::create();
        $resourceServer = $this->createMock(ResourceServer::class);
        $resourceServer->method('validateAuthenticatedRequest')->willThrowException(
            OAuthServerException::accessDenied('bad')
        );
        $fallback = new OAuthAccessTokenFallback($resourceServer, $capture->idpLogging);
        $request = $this->createMock(ServerRequestInterface::class);
        try {
            $fallback->authenticate($request, $this->createMock(RequestHandlerInterface::class), static fn () => null);
        } catch (HttpUnauthorizedException) {
        }
        $this->assertSame('invalid_access_token', $capture->handler('security')->getRecords()[0]->context['failure_reason']);

        $resourceServer2 = $this->createMock(ResourceServer::class);
        $validated = $this->createMock(ServerRequestInterface::class);
        $validated->method('getAttribute')->willReturnMap([
            ['oauth_user_id', self::USER],
            ['oauth_client_id', self::CLIENT],
        ]);
        $resourceServer2->method('validateAuthenticatedRequest')->willReturn($validated);
        $fallback2 = new OAuthAccessTokenFallback($resourceServer2, $capture->idpLogging);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($this->createMock(ResponseInterface::class));
        $fallback2->authenticate(
            $this->createMock(ServerRequestInterface::class),
            $handler,
            static fn (string $user, string $client, ServerRequestInterface $req, RequestHandlerInterface $h) => $h->handle($req)
        );
        $this->assertSame(self::USER, $capture->handler('security')->getRecords()[1]->context['user_uuid']);
    }

    private function cachedJwtMiddleware(
        CapturedIdpLogging $capture,
        ?RedisCacheRepository $redis = null,
    ): CachedJwtLocalIdpAuthMiddleware {
        $redis ??= $this->createMock(RedisCacheRepository::class);
        $pvhGate = PvhAuthorizationGate::builder()
            ->redisCacheRepository($redis)
            ->logger($capture->idpLogging->pvh())
            ->idpLogging($capture->idpLogging)
            ->build();

        return new CachedJwtLocalIdpAuthMiddleware(
            $this->createMock(EntityManager::class),
            $capture->idpLogging,
            $pvhGate,
            AuthorizedClients::builder()->clientIds([self::CLIENT])->build(),
            $this->createMock(ResourceServer::class),
            new OAuthAccessTokenFallback($this->createMock(ResourceServer::class), $capture->idpLogging),
        );
    }

    private function clientRestrictedMiddleware(CapturedIdpLogging $capture): ClientRestrictedAuthMiddleware
    {
        $pvhGate = PvhAuthorizationGate::builder()
            ->redisCacheRepository($this->createMock(RedisCacheRepository::class))
            ->logger($capture->idpLogging->pvh())
            ->idpLogging($capture->idpLogging)
            ->build();

        return new ClientRestrictedAuthMiddleware(
            $capture->idpLogging,
            $this->createMock(ResourceServer::class),
            AuthorizedClients::builder()->clientIds([self::CLIENT])->build(),
            $pvhGate,
            new OAuthAccessTokenFallback($this->createMock(ResourceServer::class), $capture->idpLogging),
        );
    }

    private function samplePvh(int $nowMs = 1_700_000_000_000): string
    {
        return Pvh::encode($nowMs, Pvh::policyHash(self::CLIENT, self::POLICY, ''));
    }

    private function failureReason(CapturedIdpLogging $capture, string $message, int $skip = 0): ?string
    {
        $matches = array_values(array_filter(
            $capture->handler('security')->getRecords(),
            static fn ($record) => $record->message === $message
        ));

        return $matches[$skip]->context['failure_reason'] ?? null;
    }
}
