<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Resource\LowLatencyController;
use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\SetQueue\PubSubQueue;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Slim\Exception\HttpUnauthorizedException;

class LowLatencyControllerTest extends TestCase
{
    private const USER_UUID = 'user-123';
    private const EMAIL = 'test@example.com';
    private const AUD = 'client-1';
    private const POLICY = '[]';

    private $redisCacheRepository;
    private $redisPubSubQueue;
    private $pubSubQueueHandle;
    private $request;
    private $response;
    private $stream;
    private $controller;

    protected function setUp(): void
    {
        $devKeysDir = dirname(__DIR__, 2) . '/dev-keys';
        if (!file_exists('/tmp/private.key') && file_exists($devKeysDir . '/private.key')) {
            @copy($devKeysDir . '/private.key', '/tmp/private.key');
        }
        if (!file_exists('/tmp/public.key') && file_exists($devKeysDir . '/public.key')) {
            @copy($devKeysDir . '/public.key', '/tmp/public.key');
        }

        $_SESSION = [];

        $this->redisCacheRepository = $this->createMock(RedisCacheRepository::class);
        $this->redisPubSubQueue = $this->createMock(PubSubQueue::class);
        $this->pubSubQueueHandle = PubSubQueueHandle::builder()
            ->handle('test-handle')
            ->build();

        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);

        $this->response->method('getBody')->willReturn($this->stream);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withStatus')->willReturnSelf();
        $this->request->method('getQueryParams')->willReturn([]);

        $this->controller = new LowLatencyController(
            $this->redisCacheRepository,
            $this->redisPubSubQueue,
            $this->pubSubQueueHandle
        );
    }

    public function testValidateThrowsUnauthorizedWhenNoBearerToken(): void
    {
        $this->request->expects($this->once())
            ->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');

        $this->redisCacheRepository->expects($this->never())->method('getPvhRecord');
        $this->redisCacheRepository->expects($this->never())->method('queueUserValidation');
        $this->redisCacheRepository->expects($this->never())->method('setPvhRecord');

        $this->expectException(HttpUnauthorizedException::class);

        $this->controller->validate($this->request, $this->response);
    }

    public function testValidateSucceedsWithoutSession(): void
    {
        $_SESSION = [];
        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(pvh: $pvh);

        $this->withBearer($jwt);
        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->with(self::USER_UUID, self::AUD)
            ->willReturn(new PvhCacheRecord(self::USER_UUID, self::AUD, self::EMAIL, $pvh, null));
        $this->expectSuccessSideEffects();
        $this->expectSuccessBody();

        $result = $this->controller->validate($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testValidateThrowsUnauthorizedWhenInvalidSignature(): void
    {
        $this->redisCacheRepository->expects($this->never())->method('getPvhRecord');
        $this->redisCacheRepository->expects($this->never())->method('queueUserValidation');

        $this->request->expects($this->once())
            ->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer invalid.jwt.string');

        $this->expectException(HttpUnauthorizedException::class);

        $this->controller->validate($this->request, $this->response);
    }

    public function testValidateThrowsUnauthorizedWhenIssuerMismatch(): void
    {
        $jwt = $this->generateValidJwt(iss: 'https://evil.example');
        $this->withBearer($jwt);
        $this->redisCacheRepository->expects($this->never())->method('getPvhRecord');
        $this->redisCacheRepository->expects($this->never())->method('queueUserValidation');
        $this->redisCacheRepository->expects($this->never())->method('setPvhRecord');

        $this->expectException(HttpUnauthorizedException::class);

        $this->controller->validate($this->request, $this->response);
    }

    public function testValidateThrowsUnauthorizedWhenExpired(): void
    {
        $jwt = $this->generateValidJwt(expired: true);
        $this->withBearer($jwt);
        $this->redisCacheRepository->expects($this->never())->method('getPvhRecord');
        $this->redisCacheRepository->expects($this->never())->method('queueUserValidation');

        $this->expectException(HttpUnauthorizedException::class);

        $this->controller->validate($this->request, $this->response);
    }

    public function testValidateThrowsUnauthorizedWhenSubMissing(): void
    {
        $jwt = $this->generateValidJwt(sub: '');
        $this->withBearer($jwt);
        $this->redisCacheRepository->expects($this->never())->method('getPvhRecord');

        $this->expectException(HttpUnauthorizedException::class);

        $this->controller->validate($this->request, $this->response);
    }

    public function testValidateCompactJwtCurrentPvhReturns200(): void
    {
        $pvh = $this->samplePvh();
        $jwt = $this->generateCompactJwt($pvh);

        $this->withBearer($jwt);
        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->with(self::USER_UUID, self::AUD)
            ->willReturn(new PvhCacheRecord(self::USER_UUID, self::AUD, self::EMAIL, $pvh, null));
        $this->expectSuccessSideEffects();
        $this->expectSuccessBody();

        $result = $this->controller->validate($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testValidateCacheHitCurrentPvhReturns200AndEnqueues(): void
    {
        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(pvh: $pvh);
        $this->withBearer($jwt);

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->with(self::USER_UUID, self::AUD)
            ->willReturn(new PvhCacheRecord(self::USER_UUID, self::AUD, self::EMAIL, $pvh, 'prev-not-used'));
        $this->redisCacheRepository->expects($this->never())->method('setPvhRecord');
        $this->expectSuccessSideEffects();
        $this->expectSuccessBody();

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
    }

    public function testValidateCacheHitPrevPvhReturns409WithoutEnqueue(): void
    {
        $current = $this->samplePvh(1_700_000_000_001);
        $prev = $this->samplePvh(1_700_000_000_000);
        $jwt = $this->generateValidJwt(pvh: $prev);
        $this->withBearer($jwt);

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(self::USER_UUID, self::AUD, self::EMAIL, $current, $prev));
        $this->redisCacheRepository->expects($this->never())->method('queueUserValidation');
        $this->redisCacheRepository->expects($this->never())->method('setPvhRecord');
        $this->redisPubSubQueue->expects($this->never())->method('publish');

        $this->stream->expects($this->once())
            ->method('write')
            ->with(json_encode(['error' => 'stale_token']));
        $this->response->expects($this->once())->method('withStatus')->with(409);

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
    }

    public function testValidateCacheHitUnknownPvhReturns401WithoutEnqueue(): void
    {
        $jwt = $this->generateValidJwt(pvh: $this->samplePvh(1_800_000_000_000));
        $this->withBearer($jwt);

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(
                self::USER_UUID,
                self::AUD,
                self::EMAIL,
                $this->samplePvh(1_700_000_000_001),
                $this->samplePvh(1_700_000_000_000)
            ));
        $this->redisCacheRepository->expects($this->never())->method('queueUserValidation');
        $this->redisCacheRepository->expects($this->never())->method('setPvhRecord');

        $this->expectException(HttpUnauthorizedException::class);

        $this->controller->validate($this->request, $this->response);
    }

    public function testValidateCacheMissSeedsPresentedPvhAndReturns200(): void
    {
        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(pvh: $pvh);
        $this->withBearer($jwt);

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(null);
        $this->redisCacheRepository->expects($this->once())
            ->method('setPvhRecord')
            ->with($this->callback(function (PvhCacheRecord $record) use ($pvh): bool {
                return $record->getUserUuid() === self::USER_UUID
                    && $record->getAud() === self::AUD
                    && $record->getEmail() === self::EMAIL
                    && $record->getPvh() === $pvh
                    && $record->getPrevPvh() === null;
            }));
        $this->expectSuccessSideEffects();
        $this->expectSuccessBody();

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
    }

    public function testValidateFatJwtWithoutPvhComparesHashPrefixOnHit(): void
    {
        $hash = Pvh::policyHash(self::AUD, self::POLICY, '');
        $current = Pvh::encode(1_700_000_000_000, $hash);
        $jwt = $this->generateValidJwt();
        $this->withBearer($jwt);

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(self::USER_UUID, self::AUD, self::EMAIL, $current, null));
        $this->redisCacheRepository->expects($this->never())->method('setPvhRecord');
        $this->expectSuccessSideEffects();
        $this->expectSuccessBody();

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
    }

    public function testValidateFatJwtWithoutPvhOnMissSeedsEncodedPvh(): void
    {
        $hash = Pvh::policyHash(self::AUD, self::POLICY, '');
        $jwt = $this->generateValidJwt();
        $this->withBearer($jwt);

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(null);
        $this->redisCacheRepository->expects($this->once())
            ->method('setPvhRecord')
            ->with($this->callback(function (PvhCacheRecord $record) use ($hash): bool {
                return $record->getPrevPvh() === null
                    && $record->getUserUuid() === self::USER_UUID
                    && Pvh::hashPrefixHex($record->getPvh()) === bin2hex(substr($hash, 0, Pvh::HASH_PREFIX_BYTE_LENGTH));
            }));
        $this->expectSuccessSideEffects();
        $this->expectSuccessBody();

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
    }

    public function testValidateFatJwtWithoutPvhPrevHashPrefixReturns409(): void
    {
        $currentHash = Pvh::policyHash(self::AUD, '["newer"]', '');
        $prevHash = Pvh::policyHash(self::AUD, self::POLICY, '');
        $jwt = $this->generateValidJwt();
        $this->withBearer($jwt);

        $this->redisCacheRepository->expects($this->once())
            ->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(
                self::USER_UUID,
                self::AUD,
                self::EMAIL,
                Pvh::encode(1_700_000_000_001, $currentHash),
                Pvh::encode(1_700_000_000_000, $prevHash)
            ));
        $this->redisCacheRepository->expects($this->never())->method('queueUserValidation');
        $this->redisCacheRepository->expects($this->never())->method('setPvhRecord');

        $this->stream->expects($this->once())
            ->method('write')
            ->with(json_encode(['error' => 'stale_token']));
        $this->response->expects($this->once())->method('withStatus')->with(409);

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
    }

    public function testValidateDefault200BodyOmitsJwt(): void
    {
        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(pvh: $pvh);
        $this->withBearer($jwt);
        $this->redisCacheRepository->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(self::USER_UUID, self::AUD, self::EMAIL, $pvh, null));
        $this->expectSuccessSideEffects();

        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->callback(function (string $json) use ($jwt): bool {
                $data = json_decode($json, true);
                return $data === ['id' => self::USER_UUID, 'email' => self::EMAIL]
                    && !array_key_exists('jwt', $data)
                    && !str_contains($json, $jwt);
            }));

        $this->controller->validate($this->request, $this->response);
    }

    public function testValidateJwtQueryEchoesPresentedTokenOnly(): void
    {
        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(pvh: $pvh);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ' . $jwt);
        $this->request->method('getQueryParams')->willReturn(['jwt' => '1']);

        $this->redisCacheRepository->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(self::USER_UUID, self::AUD, self::EMAIL, $pvh, null));
        $this->expectSuccessSideEffects();

        $this->stream->expects($this->once())
            ->method('write')
            ->with(json_encode([
                'id' => self::USER_UUID,
                'email' => self::EMAIL,
                'jwt' => $jwt,
            ]));

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
    }

    public function testValidateDoesNotCallLegacySerializeCache(): void
    {
        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(pvh: $pvh);
        $this->withBearer($jwt);
        $this->redisCacheRepository->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(self::USER_UUID, self::AUD, self::EMAIL, $pvh, null));
        $this->redisCacheRepository->expects($this->never())->method('getUser');
        $this->redisCacheRepository->expects($this->never())->method('setUser');
        $this->redisCacheRepository->expects($this->never())->method('cacheValidatedUser');
        $this->expectSuccessSideEffects();
        $this->expectSuccessBody();

        $this->controller->validate($this->request, $this->response);
    }

    private function withBearer(string $jwt): void
    {
        $this->request->expects($this->once())
            ->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer ' . $jwt);
    }

    private function expectSuccessSideEffects(): void
    {
        $this->redisCacheRepository->expects($this->once())
            ->method('queueUserValidation')
            ->with(self::USER_UUID, self::AUD);
        $this->redisPubSubQueue->expects($this->once())
            ->method('publish')
            ->with('test-handle', self::USER_UUID, self::EMAIL);
    }

    private function expectSuccessBody(): void
    {
        $this->stream->expects($this->once())
            ->method('write')
            ->with(json_encode([
                'id' => self::USER_UUID,
                'email' => self::EMAIL,
            ]));
    }

    private function samplePvh(int $nowMs = 1_700_000_000_000): string
    {
        return Pvh::encode($nowMs, Pvh::policyHash(self::AUD, self::POLICY, ''));
    }

    /**
     * @param array<string, mixed> $overrides unused; named args below
     */
    private function generateValidJwt(
        ?string $pvh = null,
        string $iss = AuthorizationJwtAssembler::ISSUER,
        string $sub = self::USER_UUID,
        bool $expired = false,
    ): string {
        if (!file_exists('/tmp/private.key') || !file_exists('/tmp/public.key')) {
            $this->fail('dev-keys were not copied to /tmp for JWT signing');
        }

        $clock = new \Lcobucci\Clock\SystemClock(new \DateTimeZone("UTC"));
        $config = \Lcobucci\JWT\Configuration::forAsymmetricSigner(
            new \Lcobucci\JWT\Signer\Rsa\Sha256(),
            \Lcobucci\JWT\Signer\Key\InMemory::file('/tmp/private.key'),
            \Lcobucci\JWT\Signer\Key\InMemory::file('/tmp/public.key')
        );

        $now = $clock->now();
        $builder = $config->builder()
            ->issuedBy($iss)
            ->permittedFor(self::AUD)
            ->expiresAt($expired ? $now->modify('-1 hour') : $now->modify('+1 hour'))
            ->withClaim('email', self::EMAIL)
            ->withClaim('policy', self::POLICY);

        if ($sub !== '') {
            $builder = $builder->relatedTo($sub);
        }
        if ($pvh !== null) {
            $builder = $builder->withClaim('pvh', $pvh);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }

    private function generateCompactJwt(string $pvh): string
    {
        if (!file_exists('/tmp/private.key') || !file_exists('/tmp/public.key')) {
            $this->fail('dev-keys were not copied to /tmp for JWT signing');
        }

        $clock = new \Lcobucci\Clock\SystemClock(new \DateTimeZone("UTC"));
        $config = \Lcobucci\JWT\Configuration::forAsymmetricSigner(
            new \Lcobucci\JWT\Signer\Rsa\Sha256(),
            \Lcobucci\JWT\Signer\Key\InMemory::file('/tmp/private.key'),
            \Lcobucci\JWT\Signer\Key\InMemory::file('/tmp/public.key')
        );

        $now = $clock->now();
        $token = $config->builder()
            ->issuedBy(AuthorizationJwtAssembler::ISSUER)
            ->permittedFor(self::AUD)
            ->relatedTo(self::USER_UUID)
            ->expiresAt($now->modify('+1 hour'))
            ->withClaim('pvh', $pvh)
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }
}
