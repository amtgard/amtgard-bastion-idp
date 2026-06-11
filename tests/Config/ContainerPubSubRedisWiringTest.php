<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\IdP\Utility\Redis\PubSubRedisConfig;
use Amtgard\SetQueue\DataStructure\SetQueue;
use Amtgard\SetQueue\PubSubQueue;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors config/container.php pub/sub Redis wiring.
 */
class ContainerPubSubRedisWiringTest extends TestCase
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

    public function testSetQueueUsesConfiguredQueueNameAndRedisHost(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'amtgard-idp-sessions';
        $_ENV['REDIS_PUBSUB_PORT'] = '6379';
        $_ENV['REDIS_PUBSUB_QUEUE_NAME'] = 'amtgard-idp-prod';

        $config = PubSubRedisConfig::dataStructureConfig();

        $this->assertSame('amtgard-idp-prod', PubSubRedisConfig::queueName());
        $this->assertSame('amtgard-idp-sessions', $config->getConfig()['host']);
    }

    public function testPubSubQueueHandleUsesConfiguredQueueName(): void
    {
        $_ENV['REDIS_PUBSUB_QUEUE_NAME'] = 'amtgard-idp-prod';

        $queueName = PubSubRedisConfig::queueName();
        $queue = $this->createMock(SetQueue::class);
        $pubSub = $this->createMock(PubSubQueue::class);

        $pubSub->expects($this->once())
            ->method('addQueue')
            ->with('amtgard-idp-prod', $queue)
            ->willReturn($queueName);

        $pubSub->addQueue($queueName, $queue);

        $handle = PubSubQueueHandle::builder()->handle($queueName)->build();

        $this->assertSame('amtgard-idp-prod', $handle->getHandle());
    }
}
