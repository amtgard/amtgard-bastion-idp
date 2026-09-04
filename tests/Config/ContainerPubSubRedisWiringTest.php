<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\IdP\Utility\PvhQueueHandle;
use Amtgard\IdP\Utility\PvhSetQueue;
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
            $_ENV['REDIS_PUBSUB_QUEUE_NAME'],
            $_ENV['REDIS_PVH_QUEUE_NAME']
        );
    }

    public function testSetQueueUsesConfiguredQueueNameAndRedisHost(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'amtgard-idp-sessions';
        $_ENV['REDIS_PUBSUB_PORT'] = '6379';
        $_ENV['REDIS_PUBSUB_QUEUE_NAME'] = 'amtgard-idp-prod';

        $config = PubSubRedisConfig::dataStructureConfig();

        $this->assertSame('amtgard-idp-prod', PubSubRedisConfig::queueName());
        $this->assertSame('amtgard-idp-sessions', PubSubRedisConfig::host());
        $this->assertSame(6379, $config->getConfig()['port']);
    }

    public function testPubSubQueueHandleUsesConfiguredQueueName(): void
    {
        $_ENV['REDIS_PUBSUB_QUEUE_NAME'] = 'amtgard-idp-prod';

        $queueName = PubSubRedisConfig::queueName();
        $queue = $this->createStub(SetQueue::class);
        $pubSub = $this->createMock(PubSubQueue::class);

        $pubSub->expects($this->once())
            ->method('addQueue')
            ->with('amtgard-idp-prod', $queue)
            ->willReturn($queueName);

        $pubSub->addQueue($queueName, $queue);

        $handle = PubSubQueueHandle::builder()->handle($queueName)->build();

        $this->assertSame('amtgard-idp-prod', $handle->getHandle());
    }

    public function testPvhQueueIsDistinctFromPresenceQueue(): void
    {
        $_ENV['REDIS_PUBSUB_QUEUE_NAME'] = 'amtgard-idp-prod';
        $_ENV['REDIS_PVH_QUEUE_NAME'] = 'amtgard-idp-pvh-prod';

        $this->assertSame('amtgard-idp-prod', PubSubRedisConfig::queueName());
        $this->assertSame('amtgard-idp-pvh-prod', PubSubRedisConfig::pvhQueueName());
        $this->assertNotSame(PubSubRedisConfig::queueName(), PubSubRedisConfig::pvhQueueName());
    }

    public function testPvhQueueHandleAddsSecondQueueOnSharedPubSub(): void
    {
        $_ENV['REDIS_PVH_QUEUE_NAME'] = 'amtgard-idp-pvh-prod';

        $presenceQueue = $this->createStub(SetQueue::class);
        $pvhQueue = $this->createStub(PvhSetQueue::class);
        $pubSub = $this->createMock(PubSubQueue::class);

        $pubSub->expects($this->exactly(2))
            ->method('addQueue')
            ->willReturnCallback(function (string $name, SetQueue $queue) use ($presenceQueue, $pvhQueue) {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    $this->assertSame('amtgard-idp', $name);
                    $this->assertSame($presenceQueue, $queue);
                } else {
                    $this->assertSame('amtgard-idp-pvh-prod', $name);
                    $this->assertSame($pvhQueue, $queue);
                }

                return $name;
            });

        $presenceName = PubSubRedisConfig::queueName();
        $pvhName = PubSubRedisConfig::pvhQueueName();
        $pubSub->addQueue($presenceName, $presenceQueue);
        $pubSub->addQueue($pvhName, $pvhQueue);

        $presenceHandle = PubSubQueueHandle::builder()->handle($presenceName)->build();
        $pvhHandle = PvhQueueHandle::builder()->handle($pvhName)->build();

        $this->assertSame('amtgard-idp', $presenceHandle->getHandle());
        $this->assertSame('amtgard-idp-pvh-prod', $pvhHandle->getHandle());
    }

    public function testContainerDefinesDistinctPvhSetQueueAndHandle(): void
    {
        $definitions = require __DIR__ . '/../../config/container.php';

        $this->assertArrayHasKey(SetQueue::class, $definitions);
        $this->assertArrayHasKey(PubSubQueueHandle::class, $definitions);
        $this->assertArrayHasKey(PvhSetQueue::class, $definitions);
        $this->assertArrayHasKey(PvhQueueHandle::class, $definitions);
        $this->assertNotSame($definitions[SetQueue::class], $definitions[PvhSetQueue::class]);
    }
}
