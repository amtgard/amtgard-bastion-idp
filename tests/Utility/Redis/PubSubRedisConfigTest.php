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

        $config = PubSubRedisConfig::dataStructureConfig()->getConfig();
        $this->assertSame('amtgard-idp-sessions', $config['host']);
        $this->assertSame(6380, $config['port']);
    }

    public function testTrimsWhitespaceFromHostAndQueueName(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = '  amtgard-idp-sessions  ';
        $_ENV['REDIS_PUBSUB_QUEUE_NAME'] = '  amtgard-idp  ';

        $this->assertSame('amtgard-idp-sessions', PubSubRedisConfig::host());
        $this->assertSame('amtgard-idp', PubSubRedisConfig::queueName());
    }

    public function testConnectUsesConfiguredHostAndPort(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'amtgard-idp-sessions';
        $_ENV['REDIS_PUBSUB_PORT'] = '6379';
        $_ENV['REDIS_PUBSUB_DB'] = '0';

        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('pconnect')
            ->with('amtgard-idp-sessions', 6379)
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
}
