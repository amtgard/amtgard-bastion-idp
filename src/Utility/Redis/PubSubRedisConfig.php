<?php
declare(strict_types=1);

namespace Amtgard\IdP\Utility\Redis;

use Amtgard\SetQueue\DataStructure\Impl\Redis\RedisDataStructureConfig;
use Redis;

/**
 * Pub/sub queue and JWT validation cache Redis settings. Production blue-green
 * uses the shared amtgard-idp-sessions container; local dev defaults to
 * in-container Redis on 127.0.0.1.
 */
class PubSubRedisConfig
{
    public static function host(): string
    {
        return trim((string) ($_ENV['REDIS_PUBSUB_HOST'] ?? '127.0.0.1'));
    }

    public static function port(): int
    {
        return (int) ($_ENV['REDIS_PUBSUB_PORT'] ?? 6379);
    }

    public static function database(): int
    {
        return (int) ($_ENV['REDIS_PUBSUB_DB'] ?? 0);
    }

    public static function queueName(): string
    {
        return trim((string) ($_ENV['REDIS_PUBSUB_QUEUE_NAME'] ?? 'amtgard-idp'));
    }

    public static function dataStructureConfig(): RedisDataStructureConfig
    {
        $config = new RedisDataStructureConfig();
        $config->setConfig([
            'host' => self::host(),
            'port' => self::port(),
        ]);

        return $config;
    }

    public static function connect(Redis $redis): Redis
    {
        $redis->pconnect(self::host(), self::port());

        $database = self::database();
        if ($database !== 0) {
            $redis->select($database);
        }

        return $redis;
    }
}
