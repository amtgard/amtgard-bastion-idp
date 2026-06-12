<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Redis;

use Amtgard\IdP\Utility\Redis\PubSubRedisConfig;
use PHPUnit\Framework\TestCase;

class PubSubRedisConfigTest extends TestCase
{
    protected function setUp(): void
    {
        unset(
            $_ENV['REDIS_PUBSUB_HOST'],
            $_ENV['REDIS_PUBSUB_PORT'],
            $_ENV['REDIS_PUBSUB_DB'],
            $_ENV['REDIS_PUBSUB_QUEUE_NAME']
        );
    }

    public function testDefaultsToLocalRedisForDev(): void
    {
        $this->assertSame('127.0.0.1', PubSubRedisConfig::host());
        $this->assertSame(6379, PubSubRedisConfig::port());
        $this->assertSame(0, PubSubRedisConfig::database());
        $this->assertSame('amtgard-idp', PubSubRedisConfig::queueName());

        $config = PubSubRedisConfig::dataStructureConfig()->getConfig();
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame(6379, $config['port']);
    }

    public function testUsesSharedRedisFromEnvironment(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'amtgard-idp-sessions';
        $_ENV['REDIS_PUBSUB_PORT'] = '6380';
        $_ENV['REDIS_PUBSUB_DB'] = '2';
        $_ENV['REDIS_PUBSUB_QUEUE_NAME'] = 'amtgard-idp-prod';

        $this->assertSame('amtgard-idp-sessions', PubSubRedisConfig::host());
        $this->assertSame(6380, PubSubRedisConfig::port());
        $this->assertSame(2, PubSubRedisConfig::database());
        $this->assertSame('amtgard-idp-prod', PubSubRedisConfig::queueName());
    }

    public function testDataStructureConfigFallsBackWhenPrimaryHostUnreachable(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'unreachable-redis-host.invalid';
        $_ENV['REDIS_PUBSUB_PORT'] = '6379';

        $config = PubSubRedisConfig::dataStructureConfig()->getConfig();
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame(6379, $config['port']);
    }

    public function testDataStructureConfigUsesProductionHostWhenItResolves(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'amtgard-idp-sessions';
        $_ENV['REDIS_PUBSUB_PORT'] = '6379';

        if (!self::hostResolvesForTest('amtgard-idp-sessions')) {
            $config = PubSubRedisConfig::dataStructureConfig()->getConfig();
            $this->assertSame('127.0.0.1', $config['host']);
            return;
        }

        $config = PubSubRedisConfig::dataStructureConfig()->getConfig();
        $this->assertSame('amtgard-idp-sessions', $config['host']);
    }

    private static function hostResolvesForTest(string $host): bool
    {
        return gethostbyname($host) !== $host;
    }

    public function testTrimsWhitespaceFromHostAndQueueName(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = '  amtgard-idp-sessions  ';
        $_ENV['REDIS_PUBSUB_QUEUE_NAME'] = '  amtgard-idp  ';

        $this->assertSame('amtgard-idp-sessions', PubSubRedisConfig::host());
        $this->assertSame('amtgard-idp', PubSubRedisConfig::queueName());
    }

    public function testLocalhostIsUsedDirectlyWithoutFallbackCandidate(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'localhost';
        $_ENV['REDIS_PUBSUB_PORT'] = '6381';

        $config = PubSubRedisConfig::dataStructureConfig()->getConfig();

        $this->assertSame('localhost', $config['host']);
        $this->assertSame(6381, $config['port']);
    }

    public function testPortAndDatabaseCastEnvironmentValuesToIntegers(): void
    {
        $_ENV['REDIS_PUBSUB_PORT'] = '6380';
        $_ENV['REDIS_PUBSUB_DB'] = '03';

        $this->assertSame(6380, PubSubRedisConfig::port());
        $this->assertSame(3, PubSubRedisConfig::database());
    }

    public function testConnectUsesConfiguredHostAndPort(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'amtgard-idp-sessions';
        $_ENV['REDIS_PUBSUB_PORT'] = '6379';
        $_ENV['REDIS_PUBSUB_DB'] = '0';

        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('pconnect')
            ->with('amtgard-idp-sessions', 6379, 1.0)
            ->willReturn(true);
        $redis->expects($this->never())->method('select');

        $this->assertSame($redis, PubSubRedisConfig::connect($redis));
    }

    public function testConnectSelectsNonDefaultDatabase(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'amtgard-idp-sessions';
        $_ENV['REDIS_PUBSUB_DB'] = '2';

        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())->method('pconnect')->willReturn(true);
        $redis->expects($this->once())->method('select')->with(2)->willReturn(true);

        PubSubRedisConfig::connect($redis);
    }

    public function testConnectFallsBackToLocalhostWhenPrimaryUnreachable(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('redis extension required');
        }

        $_ENV['REDIS_PUBSUB_HOST'] = 'unreachable-redis-host.invalid';
        $_ENV['REDIS_PUBSUB_PORT'] = '6379';
        $_ENV['REDIS_PUBSUB_DB'] = '0';

        try {
            $connected = PubSubRedisConfig::connect(new \Redis());
            $this->assertTrue($connected->isConnected());
        } catch (\RedisException) {
            $this->markTestSkipped('Local Redis not available for fallback test');
        }
    }

    public function testConnectThrowsWhenAllHostCandidatesFail(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'unreachable-redis-host.invalid';
        $_ENV['REDIS_PUBSUB_PORT'] = '6379';

        $redis = new class extends \Redis {
            public function pconnect(
                $host,
                $port = 6379,
                $timeout = 0,
                $persistent_id = null,
                $retry_interval = 0,
                $read_timeout = 0,
                $context = null
            ): bool {
                return false;
            }
        };

        $this->expectException(\RedisException::class);
        PubSubRedisConfig::connect($redis);
    }
}
