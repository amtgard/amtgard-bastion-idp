<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Middleware\CachedJwtLocalIdpAuthMiddleware;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\PvhCacheRecord;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

class CachedJwtLocalIdpAuthMiddlewareTest extends TestCase
{
    private const USER = 'user-123';
    private const CLIENT = 'client-1';
    private const EMAIL = 'test@example.com';
    private const POLICY = '[]';

    private $entityManager;
    private $logger;
    private $redisCacheRepository;
    private $authorizedClients;
    private $resourceServer;
    private $request;
    private $handler;
    private $response;
    private $middleware;

    protected function setUp(): void
    {
        $devKeysDir = dirname(__DIR__, 2) . '/dev-keys';
        if (!file_exists('/tmp/private.key') && file_exists($devKeysDir . '/private.key')) {
            @copy($devKeysDir . '/private.key', '/tmp/private.key');
        }
        if (!file_exists('/tmp/public.key') && file_exists($devKeysDir . '/public.key')) {
            @copy($devKeysDir . '/public.key', '/tmp/public.key');
        }

        $this->entityManager = $this->createMock(EntityManager::class);
        \Amtgard\ActiveRecordOrm\EntityManager::configure($this->entityManager, true);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->redisCacheRepository = $this->createMock(RedisCacheRepository::class);
        $this->authorizedClients = AuthorizedClients::builder()
            ->clientIds(['client-1'])
            ->build();
        $this->resourceServer = $this->createMock(ResourceServer::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);

        $this->middleware = new CachedJwtLocalIdpAuthMiddleware(
            $this->entityManager,
            $this->logger,
            $this->redisCacheRepository,
            $this->authorizedClients,
            $this->resourceServer
        );
    }

    public function testProcessThrowsUnauthorizedOnMissingJwt(): void
    {
        $this->request->method('getHeaderLine')->with('Authorization')->willReturn('');
        $this->resourceServer->method('validateAuthenticatedRequest')
            ->willThrowException(new \League\OAuth2\Server\Exception\OAuthServerException('missing', 0, 'missing'));
        $this->expectException(HttpUnauthorizedException::class);
        $this->middleware->process($this->request, $this->handler);
    }

    public function testProcessSuccessWhenCurrentPvhMatches(): void
    {
        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(self::USER, self::CLIENT, $pvh);
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn("Bearer {$jwt}");

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->with(self::USER, self::CLIENT)
            ->willReturn(new PvhCacheRecord(self::USER, self::CLIENT, self::EMAIL, $pvh, null));
        $this->redisCacheRepository->expects($this->never())->method('isUserInCache');
        $this->redisCacheRepository->expects($this->never())->method('cacheValidatedUser');

        $this->handler->expects($this->once())
            ->method('handle')
            ->with($this->request)
            ->willReturn($this->response);

        @session_start();
        $_SESSION = [];

        $result = $this->middleware->process($this->request, $this->handler);
        $this->assertSame($this->response, $result);
        $this->assertSame(self::USER, $_SESSION['user_id']);
        $this->assertSame(self::CLIENT, $_SESSION['client_id']);
    }

    public function testProcessSuccessWhenFatJwtHashPrefixMatches(): void
    {
        $hash = Pvh::policyHash(self::CLIENT, self::POLICY, '');
        $current = Pvh::encode(1_700_000_000_000, $hash);
        $jwt = $this->generateValidJwt(self::USER, self::CLIENT, null, self::POLICY);
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn("Bearer {$jwt}");

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(self::USER, self::CLIENT, self::EMAIL, $current, null));

        $this->handler->expects($this->once())
            ->method('handle')
            ->with($this->request)
            ->willReturn($this->response);

        @session_start();
        $_SESSION = [];

        $result = $this->middleware->process($this->request, $this->handler);
        $this->assertSame($this->response, $result);
        $this->assertSame(self::USER, $_SESSION['user_id']);
    }

    public function testProcessCacheMissReturns401AndDoesNotSeed(): void
    {
        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(self::USER, self::CLIENT, $pvh);
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn("Bearer {$jwt}");

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->with(self::USER, self::CLIENT)
            ->willReturn(null);
        $this->redisCacheRepository->expects($this->never())->method('cacheValidatedUser');
        $this->redisCacheRepository->expects($this->never())->method('setPvhRecord');
        $this->handler->expects($this->never())->method('handle');

        @session_start();
        $_SESSION = [];

        try {
            $this->middleware->process($this->request, $this->handler);
            $this->fail('expected HttpUnauthorizedException');
        } catch (HttpUnauthorizedException) {
            $this->assertArrayNotHasKey('user_id', $_SESSION);
        }
    }

    public function testProcessPrevPvhReturns409StaleToken(): void
    {
        $current = $this->samplePvh(1_700_000_000_001);
        $prev = $this->samplePvh(1_700_000_000_000);
        $jwt = $this->generateValidJwt(self::USER, self::CLIENT, $prev);
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn("Bearer {$jwt}");

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(self::USER, self::CLIENT, self::EMAIL, $current, $prev));
        $this->handler->expects($this->never())->method('handle');
        $this->redisCacheRepository->expects($this->never())->method('cacheValidatedUser');

        @session_start();
        $_SESSION = [];

        $result = $this->middleware->process($this->request, $this->handler);
        $this->assertSame(409, $result->getStatusCode());
        $this->assertSame(['error' => 'stale_token'], json_decode((string) $result->getBody(), true));
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testProcessUnknownPvhReturns401(): void
    {
        $jwt = $this->generateValidJwt(self::USER, self::CLIENT, $this->samplePvh(1_800_000_000_000));
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn("Bearer {$jwt}");

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(
                self::USER,
                self::CLIENT,
                self::EMAIL,
                $this->samplePvh(1_700_000_000_001),
                $this->samplePvh(1_700_000_000_000)
            ));
        $this->handler->expects($this->never())->method('handle');

        @session_start();
        $_SESSION = [];

        $this->expectException(HttpUnauthorizedException::class);
        $this->middleware->process($this->request, $this->handler);
    }

    private function samplePvh(int $nowMs = 1_700_000_000_000): string
    {
        return Pvh::encode($nowMs, Pvh::policyHash(self::CLIENT, self::POLICY, ''));
    }

    private function generateValidJwt(string $userId, string $clientId, ?string $pvh = null, ?string $policy = null): string
    {
        $clock = new \Lcobucci\Clock\SystemClock(new \DateTimeZone("UTC"));
        $config = \Lcobucci\JWT\Configuration::forAsymmetricSigner(
            new \Lcobucci\JWT\Signer\Rsa\Sha256(),
            \Lcobucci\JWT\Signer\Key\InMemory::file('/tmp/private.key'),
            \Lcobucci\JWT\Signer\Key\InMemory::file('/tmp/public.key')
        );

        $now = $clock->now();
        $builder = $config->builder()
            ->issuedBy('http://localhost')
            ->permittedFor($clientId)
            ->relatedTo($userId)
            ->expiresAt($now->modify('+1 hour'));
        if ($pvh !== null) {
            $builder = $builder->withClaim('pvh', $pvh);
        }
        if ($policy !== null) {
            $builder = $builder->withClaim('policy', $policy);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }
}
