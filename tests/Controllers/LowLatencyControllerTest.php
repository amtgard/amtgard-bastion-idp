<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Resource\LowLatencyController;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\Pvh\PvhAuthorizationGate;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\SetQueue\PubSubQueue;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

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
        FirebaseJwtTestFactory::ensureKeys();

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

        $pvhAuthorizationGate = PvhAuthorizationGate::builder()
            ->redisCacheRepository($this->redisCacheRepository)
            ->build();

        $this->controller = new LowLatencyController(
            $this->redisCacheRepository,
            $this->redisPubSubQueue,
            $this->pubSubQueueHandle,
            $pvhAuthorizationGate,
            $this->createStub(LoggerInterface::class)
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
        $this->expectUnauthorizedJson();

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
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

        $this->expectUnauthorizedJson();

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
    }

    public function testValidateThrowsUnauthorizedWhenIssuerMismatch(): void
    {
        $jwt = $this->generateValidJwt(iss: 'https://evil.example');
        $this->withBearer($jwt);
        $this->redisCacheRepository->expects($this->never())->method('getPvhRecord');
        $this->redisCacheRepository->expects($this->never())->method('queueUserValidation');
        $this->redisCacheRepository->expects($this->never())->method('setPvhRecord');
        $this->expectUnauthorizedJson();

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
    }

    public function testValidateThrowsUnauthorizedWhenExpired(): void
    {
        $jwt = $this->generateValidJwt(expired: true);
        $this->withBearer($jwt);
        $this->redisCacheRepository->expects($this->never())->method('getPvhRecord');
        $this->redisCacheRepository->expects($this->never())->method('queueUserValidation');
        $this->expectUnauthorizedJson();

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
    }

    public function testValidateThrowsUnauthorizedWhenSubMissing(): void
    {
        $jwt = $this->generateValidJwt(sub: '');
        $this->withBearer($jwt);
        $this->redisCacheRepository->expects($this->never())->method('getPvhRecord');
        $this->expectUnauthorizedJson();

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
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
        $this->redisPubSubQueue->expects($this->never())->method('publish');
        $this->expectUnauthorizedJson();

        $this->assertSame($this->response, $this->controller->validate($this->request, $this->response));
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

    public function testValidateHitPublishesPresenceAndQueuesRefresh(): void
    {
        $pvh = $this->samplePvh();
        $jwt = $this->generateValidJwt(pvh: $pvh);
        $this->withBearer($jwt);
        $this->redisCacheRepository->method('getPvhRecord')
            ->willReturn(new PvhCacheRecord(self::USER_UUID, self::AUD, self::EMAIL, $pvh, null));
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

    private function expectUnauthorizedJson(): void
    {
        $this->stream->expects($this->once())
            ->method('write')
            ->with(json_encode(['error' => 'unauthorized']));
        $this->response->expects($this->once())->method('withStatus')->with(401);
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
        FirebaseJwtTestFactory::assertKeysAvailable();

        return FirebaseJwtTestFactory::lowLatencyAuthorization(
            self::AUD,
            $sub,
            self::EMAIL,
            self::POLICY,
            $pvh,
            $iss,
            $expired,
        );
    }

    private function generateCompactJwt(string $pvh): string
    {
        FirebaseJwtTestFactory::assertKeysAvailable();

        return FirebaseJwtTestFactory::compactAuthorizationWithPvh(
            self::AUD,
            self::USER_UUID,
            $pvh,
            AuthorizationJwtAssembler::ISSUER,
        );
    }
}
