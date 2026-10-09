<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Utility\Log\RequestLogContext;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\PvhQueueHandle;
use Amtgard\SetQueue\PubSubQueue;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisCacheRepositoryLoggingTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestLogContext::reset();
    }

    public function testGetPvhRecordLogsCacheMiss(): void
    {
        $capture = CapturedIdpLogging::create();
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn(false);

        $this->repository($capture, $redis)->getPvhRecord('uuid-1', 'client-a');

        $record = $this->findRecord($capture, 'pvh redis cache miss');
        $this->assertSame(Level::Debug, $record->level);
        $this->assertSame('miss', $record->context['cache']);
    }

    public function testQueueUserValidationIncludesRequestIdInPayloadAndLog(): void
    {
        $capture = CapturedIdpLogging::create();
        RequestLogContext::set('req-enqueue-1');

        $pubSub = $this->createMock(PubSubQueue::class);
        $pubSub->expects($this->once())
            ->method('publish')
            ->with(
                'amtgard-idp-pvh',
                'uuid-1:client-a',
                $this->callback(static function (string $json): bool {
                    $data = json_decode($json, true);

                    return is_array($data)
                        && ($data['request_id'] ?? null) === 'req-enqueue-1';
                })
            );

        $this->repository($capture, $this->createStub(Redis::class), $pubSub)
            ->queueUserValidation('uuid-1', 'client-a');

        $record = $this->findRecord($capture, 'jwt pvh enqueue');
        $this->assertSame(Level::Notice, $record->level);
        $this->assertSame('req-enqueue-1', $record->context['request_id']);
    }

    public function testInvalidateUserLogsKeysDeleted(): void
    {
        $capture = CapturedIdpLogging::create();
        $redis = $this->createStub(Redis::class);
        $redis->method('del')->willReturn(1);
        $redis->method('scan')->willReturn(false);

        $this->repository($capture, $redis)->invalidateUser('user-1');

        $record = $this->findRecord($capture, 'pvh cache invalidated on logout');
        $this->assertSame(Level::Info, $record->level);
        $this->assertSame(1, $record->context['keys_deleted']);
    }

    public function testSetPvhRecordLogsDebugWrite(): void
    {
        $capture = CapturedIdpLogging::create();
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('set');

        $record = new PvhCacheRecord(
            'uuid-1',
            'client-a',
            'a@example.com',
            str_repeat('a', 44),
            null,
        );
        $this->repository($capture, $redis)->setPvhRecord($record);

        $this->assertNotNull($this->findRecord($capture, 'pvh redis cache write'));
    }

    private function repository(
        CapturedIdpLogging $capture,
        Redis $redis,
        ?PubSubQueue $pubSub = null,
    ): RedisCacheRepository {
        return new RedisCacheRepository(
            $capture->idpLogging,
            $redis,
            $pubSub ?? $this->createStub(PubSubQueue::class),
            PvhQueueHandle::builder()->handle('amtgard-idp-pvh')->build(),
        );
    }

    private function findRecord(CapturedIdpLogging $capture, string $message): LogRecord
    {
        foreach ($capture->handler('pvh')->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Missing log record: ' . $message);
    }
}
