<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Middleware\ClientRestrictedAuthMiddleware;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\Pvh\PvhAuthorizationGate;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\Security\OAuthAccessTokenFallback;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

class ClientRestrictedAuthMiddlewareTest extends TestCase
{
    private const USER = 'user-123';
    private const CLIENT = 'valid-client';
    private const EMAIL = 'test@example.com';
    private const POLICY = '[]';

    private $entityManager;
    private $logger;
    private $resourceServer;
    private $validClients;
    private $redisCacheRepository;
    private $request;
    private $handler;
    private $response;
    private $middleware;

    protected function setUp(): void
    {
        FirebaseJwtTestFactory::ensureKeys();

        $this->entityManager = $this->createMock(EntityManager::class);
        \Amtgard\ActiveRecordOrm\EntityManager::configure($this->entityManager, true);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resourceServer = $this->createMock(ResourceServer::class);
        $this->validClients = AuthorizedClients::builder()
            ->clientIds(['valid-client'])
            ->build();
        $this->redisCacheRepository = $this->createMock(RedisCacheRepository::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);

        $pvhAuthorizationGate = PvhAuthorizationGate::builder()
            ->redisCacheRepository($this->redisCacheRepository)
            ->build();
        $oauthAccessTokenFallback = new OAuthAccessTokenFallback($this->resourceServer);

        $this->middleware = new ClientRestrictedAuthMiddleware(
            $this->entityManager,
            $this->logger,
            $this->resourceServer,
            $this->validClients,
            $pvhAuthorizationGate,
            $oauthAccessTokenFallback,
        );
    }

    public function testProcessCallsHandlerWhenSessionClientIdIsValid(): void
    {
        @session_start();
        $_SESSION['client_id'] = 'valid-client';

        $this->redisCacheRepository->expects($this->never())->method('getPvhRecord');
        $this->resourceServer->expects($this->never())->method('validateAuthenticatedRequest');

        $this->handler->expects($this->once())
            ->method('handle')
            ->with($this->request)
            ->willReturn($this->response);

        $result = $this->middleware->process($this->request, $this->handler);
        $this->assertSame($this->response, $result);
    }

    public function testProcessThrowsUnauthorizedOnMissingJwt(): void
    {
        @session_start();
        $_SESSION['client_id'] = 'invalid-client';


        $this->request->method('getHeaderLine')->with('Authorization')->willReturn('');

        $this->expectException(HttpUnauthorizedException::class);
        $this->middleware->process($this->request, $this->handler);
    }

    public function testProcessSuccessWhenCurrentPvhMatches(): void
    {
        @session_start();
        $_SESSION['client_id'] = 'invalid-client';

        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(self::USER, self::CLIENT, $pvh);
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn("Bearer {$jwt}");

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->with(self::USER, self::CLIENT)
            ->willReturn(new PvhCacheRecord(self::USER, self::CLIENT, self::EMAIL, $pvh, null));
        $this->resourceServer->expects($this->never())->method('validateAuthenticatedRequest');
        $this->redisCacheRepository->expects($this->never())->method('setPvhRecord');

        $this->handler->expects($this->once())
            ->method('handle')
            ->with($this->request)
            ->willReturn($this->response);

        $result = $this->middleware->process($this->request, $this->handler);
        $this->assertSame($this->response, $result);
        $this->assertSame(self::USER, $_SESSION['user_id']);
        $this->assertSame(self::CLIENT, $_SESSION['client_id']);
    }

    public function testProcessCacheMissSeedsPresentedPvhAndProceeds(): void
    {
        @session_start();
        $_SESSION['client_id'] = 'invalid-client';

        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(self::USER, self::CLIENT, $pvh);
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn("Bearer {$jwt}");

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(null);
        $this->redisCacheRepository->expects($this->once())
            ->method('setPvhRecord')
            ->with($this->callback(function (PvhCacheRecord $record) use ($pvh): bool {
                return $record->getUserUuid() === self::USER
                    && $record->getAud() === self::CLIENT
                    && $record->getPvh() === $pvh
                    && $record->getPrevPvh() === null;
            }));
        $this->resourceServer->expects($this->never())->method('validateAuthenticatedRequest');
        $this->handler->expects($this->once())
            ->method('handle')
            ->with($this->request)
            ->willReturn($this->response);

        $result = $this->middleware->process($this->request, $this->handler);
        $this->assertSame($this->response, $result);
        $this->assertSame(self::USER, $_SESSION['user_id']);
        $this->assertSame(self::CLIENT, $_SESSION['client_id']);
    }

    public function testProcessPrevPvhReturns409StaleToken(): void
    {
        @session_start();
        $_SESSION['client_id'] = 'invalid-client';

        $current = $this->samplePvh(1_700_000_000_001);
        $prev = $this->samplePvh(1_700_000_000_000);
        $jwt = $this->generateValidJwt(self::USER, self::CLIENT, $prev);
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn("Bearer {$jwt}");

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(self::USER, self::CLIENT, self::EMAIL, $current, $prev));
        $this->resourceServer->expects($this->never())->method('validateAuthenticatedRequest');
        $this->handler->expects($this->never())->method('handle');

        $result = $this->middleware->process($this->request, $this->handler);
        $this->assertSame(409, $result->getStatusCode());
        $this->assertSame(['error' => 'stale_token'], json_decode((string) $result->getBody(), true));
    }

    private function samplePvh(int $nowMs = 1_700_000_000_000): string
    {
        return Pvh::encode($nowMs, Pvh::policyHash(self::CLIENT, self::POLICY, ''));
    }

    private function generateValidJwt(string $userId, string $clientId, ?string $pvh = null): string
    {
        return FirebaseJwtTestFactory::authorizationForUserAndClient($userId, $clientId, $pvh);
    }
}
