<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Pvh;

use Amtgard\IdP\Services\JwtPvhRefreshService;
use Amtgard\IdP\Utility\Pvh\JwtPvhQueueProcessor;
use Amtgard\IdP\Utility\Pvh\PvhQueueMessage;
use Amtgard\SetQueue\PubSubQueue;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class M4WorkersPvhQueueLoggingTest extends TestCase
{
    public function testEncodeLogsDebugWithRequestId(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('pvh');
        $logger->pushHandler($handler);

        PvhQueueMessage::encode('uuid-1', 'client-a', 'req-from-http', $logger);

        $record = $this->findByMessage($handler, 'pvh queue message encoded');
        $this->assertSame(Level::Debug, $record->level);
        $this->assertSame('req-from-http', $record->context['request_id'] ?? null);
    }

    public function testFromJsonLogsInvalidPayloadReason(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('pvh');
        $logger->pushHandler($handler);

        $this->assertFalse(PvhQueueMessage::fromJson('{}', $logger)->isPresent());
        $record = $this->findByMessage($handler, 'pvh queue message rejected');
        $this->assertSame(Level::Error, $record->level);
        $this->assertSame(PvhQueueMessage::REASON_INVALID_PAYLOAD, $record->context['reason'] ?? null);
    }

    public function testFromJsonLogsExceptionClassOnMalformedJson(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('pvh');
        $logger->pushHandler($handler);

        $this->assertFalse(PvhQueueMessage::fromJson('not-json', $logger)->isPresent());
        $record = $this->findByMessage($handler, 'pvh queue message parse failed');
        $this->assertSame(Level::Error, $record->level);
        $this->assertArrayHasKey('exception_class', $record->context);
    }

    public function testProcessorLogsMalformedDropWithParseReason(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('pvh');
        $logger->pushHandler($handler);

        $processor = new JwtPvhQueueProcessor(
            $this->createStub(JwtPvhRefreshService::class),
            $this->createStub(PubSubQueue::class),
            'amtgard-idp-pvh',
            $logger,
            'job-abc',
        );

        $processor->onMessage('bad-key', 'not-json');

        $record = $this->findByMessage($handler, 'jwt pvh worker dropped malformed message');
        $this->assertSame(PvhQueueMessage::REASON_MALFORMED_JSON, $record->context['parse_reason'] ?? null);
        $this->assertSame('amtgard-idp-pvh', $record->context['queue'] ?? null);
    }

    public function testProcessorLogsDequeueWithRequestIdAndCriticalOnFailure(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('pvh');
        $logger->pushHandler($handler);

        $service = $this->createMock(JwtPvhRefreshService::class);
        $service->expects($this->once())
            ->method('refresh')
            ->willThrowException(new RuntimeException('boom'));

        $pubSub = $this->createMock(PubSubQueue::class);
        $pubSub->expects($this->once())
            ->method('publish')
            ->with(
                'amtgard-idp-pvh',
                'uuid-1:client-a',
                $this->stringContains('req-worker')
            );

        $processor = new JwtPvhQueueProcessor(
            $service,
            $pubSub,
            'amtgard-idp-pvh',
            $logger,
            'job-xyz',
            static function (): void {
            },
        );

        $payload = PvhQueueMessage::encode('uuid-1', 'client-a', 'req-worker');
        $processor->onMessage('uuid-1:client-a', $payload);

        $dequeued = $this->findByMessage($handler, 'jwt pvh worker dequeued');
        $this->assertSame('req-worker', $dequeued->context['request_id'] ?? null);

        $failed = $this->findByMessage($handler, 'jwt pvh worker job failed; re-publishing');
        $this->assertSame(Level::Critical, $failed->level);
        $this->assertSame(RuntimeException::class, $failed->context['exception_class'] ?? null);
    }

    private function findByMessage(TestHandler $handler, string $message): LogRecord
    {
        foreach ($handler->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Missing log record: ' . $message);
    }
}
