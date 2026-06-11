<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Redis;

use Amtgard\IdP\Tests\Support\ResetsPhpSessionState;
use Amtgard\IdP\Utility\Redis\PubSubRedisConfig;
use Amtgard\IdP\Utility\Security\SessionStorage;
use PHPUnit\Framework\TestCase;

/**
 * Production blue-green uses one shared Redis container with separate logical DBs.
 */
class SharedRedisEnvironmentTest extends TestCase
{
    use ResetsPhpSessionState;

    protected function setUp(): void
    {
        $this->captureSessionIniState();
        $this->resetPhpSessionState();
        unset(
            $_ENV['REDIS_PUBSUB_HOST'],
            $_ENV['REDIS_PUBSUB_PORT'],
            $_ENV['REDIS_PUBSUB_DB'],
            $_ENV['REDIS_PUBSUB_QUEUE_NAME'],
            $_ENV['SESSION_REDIS_HOST'],
            $_ENV['SESSION_REDIS_PORT'],
            $_ENV['SESSION_REDIS_DB'],
            $_ENV['SESSION_REDIS_PREFIX']
        );
    }

    protected function tearDown(): void
    {
        $this->restoreSessionIniState();
    }

    public function testProductionLikeEnvUsesSameHostWithSeparateDatabases(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'amtgard-idp-sessions';
        $_ENV['REDIS_PUBSUB_PORT'] = '6379';
        $_ENV['REDIS_PUBSUB_DB'] = '0';
        $_ENV['REDIS_PUBSUB_QUEUE_NAME'] = 'amtgard-idp';
        $_ENV['SESSION_REDIS_HOST'] = 'amtgard-idp-sessions';
        $_ENV['SESSION_REDIS_PORT'] = '6379';
        $_ENV['SESSION_REDIS_DB'] = '1';
        $_ENV['SESSION_REDIS_PREFIX'] = 'PHPSESS:';

        $this->assertSame(PubSubRedisConfig::host(), trim((string) $_ENV['SESSION_REDIS_HOST']));
        $this->assertNotSame(PubSubRedisConfig::database(), (int) $_ENV['SESSION_REDIS_DB']);

        SessionStorage::configure();

        $this->assertSame('redis', ini_get('session.save_handler'));
        $this->assertSame(
            'tcp://amtgard-idp-sessions:6379?database=1&prefix=PHPSESS:',
            ini_get('session.save_path')
        );
        $this->assertSame(0, PubSubRedisConfig::database());
    }
}
