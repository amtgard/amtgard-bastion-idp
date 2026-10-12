<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\Pvh\PvhAuthorizationGate;
use Amtgard\IdP\Utility\Pvh\PvhGateOutcome;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class PvhAuthorizationGateLoggingTest extends TestCase
{
    private const USER = 'user-123';
    private const AUD = 'client-1';
    private const EMAIL = 'test@example.com';
    private const POLICY = '[]';

    protected function setUp(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
    }

    public function testLogsErrorOnPreviousAndUnknownAccess(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('pvh');
        $logger->pushHandler($handler);
        $redis = $this->createMock(RedisCacheRepository::class);

        $current = $this->samplePvh(1_700_000_000_001);
        $prev = $this->samplePvh(1_700_000_000_000);
        $redis->method('getPvhRecord')->willReturn(
            new PvhCacheRecord(self::USER, self::AUD, self::EMAIL, $current, $prev)
        );

        $gate = PvhAuthorizationGate::builder()
            ->redisCacheRepository($redis)
            ->logger($logger)
            ->build();

        $gate->evaluateAndSeed(self::USER, self::AUD, ['pvh' => $prev]);
        $gateRecord = $this->gateRecord($handler);
        $this->assertSame(Level::Error, $gateRecord->level);
        $this->assertSame('Previous', $gateRecord->context['access']);

        $handler->clear();
        $redis2 = $this->createMock(RedisCacheRepository::class);
        $redis2->method('getPvhRecord')->willReturn(
            new PvhCacheRecord(self::USER, self::AUD, self::EMAIL, $current, $prev)
        );
        $gate2 = PvhAuthorizationGate::builder()
            ->redisCacheRepository($redis2)
            ->logger($logger)
            ->build();
        $gate2->evaluateAndSeed(self::USER, self::AUD, ['pvh' => $this->samplePvh(1_900_000_000_000)]);
        $gateRecord2 = $this->gateRecord($handler);
        $this->assertSame(Level::Error, $gateRecord2->level);
        $this->assertSame('Unknown', $gateRecord2->context['access']);
    }

    public function testLogsInfoOnCacheMissSeed(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('pvh');
        $logger->pushHandler($handler);
        $redis = $this->createMock(RedisCacheRepository::class);
        $redis->method('getPvhRecord')->willReturn(null);
        $redis->expects($this->once())->method('setPvhRecord');

        $pvh = $this->samplePvh();
        $gate = PvhAuthorizationGate::builder()
            ->redisCacheRepository($redis)
            ->logger($logger)
            ->build();

        $outcome = $gate->evaluateAndSeed(self::USER, self::AUD, ['pvh' => $pvh]);
        $this->assertSame(PvhGateOutcome::Proceed, $outcome);
        $gateRecord = $this->gateRecord($handler);
        $this->assertSame(Level::Info, $gateRecord->level);
        $this->assertSame('Miss', $gateRecord->context['access']);
    }

    public function testWarnsWhenLoggerUnbound(): void
    {
        $capture = CapturedIdpLogging::create();
        $redis = $this->createMock(RedisCacheRepository::class);
        $redis->method('getPvhRecord')->willReturn(null);
        $redis->method('setPvhRecord');

        $gate = PvhAuthorizationGate::builder()
            ->redisCacheRepository($redis)
            ->idpLogging($capture->idpLogging)
            ->build();

        $gate->evaluateAndSeed(self::USER, self::AUD, ['pvh' => $this->samplePvh()]);
        $this->assertSame('pvh_logger_unbound', $capture->handler('pvh')->getRecords()[0]->context['reason']);
    }

    private function samplePvh(int $nowMs = 1_700_000_000_000): string
    {
        return Pvh::encode($nowMs, Pvh::policyHash(self::AUD, self::POLICY, ''));
    }

    private function gateRecord(TestHandler $handler): \Monolog\LogRecord
    {
        $matches = array_values(array_filter(
            $handler->getRecords(),
            static fn ($record) => $record->message === 'pvh authorization gate'
        ));

        return $matches[0];
    }
}
