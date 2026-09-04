<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\CachedValidatedUserEntity;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\PvhQueueHandle;
use Amtgard\SetQueue\PubSubQueue;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Redis;

class RedisCacheRepositoryTest extends TestCase
{
    private LoggerInterface $logger;
    private Redis $redis;
    private PubSubQueue $pubSubQueue;
    private PvhQueueHandle $pvhQueueHandle;
    private RedisCacheRepository $repository;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->redis = $this->createMock(Redis::class);
        $this->pubSubQueue = $this->createStub(PubSubQueue::class);
        $this->pvhQueueHandle = PvhQueueHandle::builder()->handle('amtgard-idp-pvh')->build();
        $this->repository = $this->makeRepository($this->redis, $this->pubSubQueue);
    }

    private function makeRepository(Redis $redis, PubSubQueue $pubSubQueue): RedisCacheRepository
    {
        return new RedisCacheRepository(
            $this->logger,
            $redis,
            $pubSubQueue,
            $this->pvhQueueHandle
        );
    }

    public function testCacheValidatedUserStoresEntityWithJwt(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->with('user-1', $this->callback(function (string $serialized) {
                $entity = unserialize($serialized);
                return $entity instanceof CachedValidatedUserEntity
                    && $entity->getUserId() === 'user-1'
                    && $entity->getEmail() === 'test@example.com'
                    && $entity->getJwt() === 'jwt-token'
                    && $entity->hasJwt();
            }));

        $this->repository->cacheValidatedUser('user-1', 'test@example.com', 'jwt-token');
    }

    public function testGetUserReturnsCachedEntityWithoutJwt(): void
    {
        $entity = CachedValidatedUserEntity::builder()
            ->userId('user-1')
            ->email('test@example.com')
            ->build();

        $this->redis->expects($this->once())
            ->method('get')
            ->with('user-1')
            ->willReturn(serialize($entity));

        $result = $this->repository->getUser('user-1');

        $this->assertInstanceOf(CachedValidatedUserEntity::class, $result);
        $this->assertSame('user-1', $result->getUserId());
        $this->assertSame('test@example.com', $result->getEmail());
        $this->assertFalse($result->hasJwt());
    }

    public function testGetUserReturnsNullWhenNotCached(): void
    {
        $this->redis->expects($this->once())
            ->method('get')
            ->with('missing-user')
            ->willReturn(false);

        $this->assertNull($this->repository->getUser('missing-user'));
    }

    public function testIsUserInCache(): void
    {
        $this->redis->expects($this->once())
            ->method('get')
            ->with('user-1')
            ->willReturn(serialize(CachedValidatedUserEntity::builder()
                ->userId('user-1')
                ->email('test@example.com')
                ->build()));

        $this->assertTrue($this->repository->isUserInCache('user-1'));
    }

    public function testInvalidateUserDeletesLegacyKeyAndPvhKeys(): void
    {
        $redis = $this->createStub(Redis::class);
        $deleted = [];
        $redis->method('del')->willReturnCallback(function ($key) use (&$deleted) {
            if (is_array($key)) {
                array_push($deleted, ...$key);
            } else {
                $deleted[] = $key;
            }

            return count(is_array($key) ? $key : [$key]);
        });

        $scanCalls = 0;
        $redis->method('scan')->willReturnCallback(function (&$iterator, $pattern = null) use (&$scanCalls) {
            $this->assertSame('pvh:user-1:*', $pattern);
            $scanCalls++;
            if ($scanCalls === 1) {
                $iterator = 17;
                return ['pvh:user-1:client-a', 'pvh:user-1:client-b'];
            }
            $iterator = 0;
            return false;
        });

        $this->makeRepository($redis, $this->pubSubQueue)->invalidateUser('user-1');

        $this->assertSame(['user-1', 'pvh:user-1:client-a', 'pvh:user-1:client-b'], $deleted);
    }

    public function testSetUserStoresSerializedEntity(): void
    {
        $entity = CachedValidatedUserEntity::builder()
            ->userId('user-2')
            ->email('other@example.com')
            ->build();

        $this->redis->expects($this->once())
            ->method('set')
            ->with('user-2', serialize($entity));

        $this->repository->setUser($entity);
    }

    public function testGetUserReturnsNullForInvalidSerializedPayload(): void
    {
        $this->redis->method('get')->with('user-bad')->willReturn(serialize(['not', 'an', 'entity']));

        $this->assertNull($this->repository->getUser('user-bad'));
    }

    public function testSetPvhRecordStoresJsonNotSerialize(): void
    {
        $record = new PvhCacheRecord(
            'uuid-1',
            'client-a',
            'a@example.com',
            'aabbccddee00112233445566778899aabbccddee00',
            'prev-pvh-hex'
        );

        $this->redis->expects($this->once())
            ->method('set')
            ->with(
                'pvh:uuid-1:client-a',
                $this->callback(function (string $json) {
                    $data = json_decode($json, true);
                    $this->assertIsArray($data);
                    $this->assertSame([
                        'user_uuid' => 'uuid-1',
                        'aud' => 'client-a',
                        'email' => 'a@example.com',
                        'pvh' => 'aabbccddee00112233445566778899aabbccddee00',
                        'prev_pvh' => 'prev-pvh-hex',
                    ], $data);
                    $this->assertFalse(@unserialize($json));

                    return true;
                })
            );

        $this->repository->setPvhRecord($record);
    }

    public function testGetPvhRecordReturnsJsonRecord(): void
    {
        $json = json_encode([
            'user_uuid' => 'uuid-1',
            'aud' => 'client-a',
            'email' => 'a@example.com',
            'pvh' => 'current-pvh',
            'prev_pvh' => null,
        ], JSON_THROW_ON_ERROR);

        $this->redis->expects($this->once())
            ->method('get')
            ->with('pvh:uuid-1:client-a')
            ->willReturn($json);

        $result = $this->repository->getPvhRecord('uuid-1', 'client-a');

        $this->assertInstanceOf(PvhCacheRecord::class, $result);
        $this->assertSame('uuid-1', $result->getUserUuid());
        $this->assertSame('client-a', $result->getAud());
        $this->assertSame('a@example.com', $result->getEmail());
        $this->assertSame('current-pvh', $result->getPvh());
        $this->assertNull($result->getPrevPvh());
    }

    public function testGetPvhRecordReturnsNullWhenMissing(): void
    {
        $this->redis->expects($this->once())
            ->method('get')
            ->with('pvh:missing:client-a')
            ->willReturn(false);

        $this->assertNull($this->repository->getPvhRecord('missing', 'client-a'));
    }

    public function testGetPvhRecordReturnsNullForInvalidJson(): void
    {
        $this->redis->method('get')->with('pvh:uuid-1:client-a')->willReturn('not-json');

        $this->assertNull($this->repository->getPvhRecord('uuid-1', 'client-a'));
    }

    public function testQueueUserValidationPublishesToPvhQueue(): void
    {
        $pubSubQueue = $this->createMock(PubSubQueue::class);
        $pubSubQueue->expects($this->once())
            ->method('publish')
            ->with(
                'amtgard-idp-pvh',
                'uuid-1:client-a',
                '{"user_uuid":"uuid-1","aud":"client-a"}'
            );

        $this->makeRepository($this->createStub(Redis::class), $pubSubQueue)
            ->queueUserValidation('uuid-1', 'client-a');
    }
}
