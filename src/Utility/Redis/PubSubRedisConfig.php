<?php
declare(strict_types=1);

namespace Amtgard\IdP\Utility\Redis;

use Amtgard\SetQueue\DataStructure\Impl\Redis\RedisDataStructureConfig;
use Redis;
use RedisException;

/**
 * Pub/sub queue and JWT validation cache Redis settings. Production blue-green
 * uses the shared amtgard-idp-sessions container; local dev defaults to
 * in-container Redis on 127.0.0.1.
 *
 * amtgard/redis-set-queue v1.1.2 method lock (composer.lock pin
 * 86ac6f37cc93d5105c7eb1a92830943a977de399). Verified against
 * vendor/amtgard/redis-set-queue/src/PubSubQueue.php. Upstream README still
 * documents send()/pump(); those methods do not exist on this pin.
 *
 * Publisher:  publish(string $queueName, key, message, bool $replace = true)
 * Register:   addQueue(string $queueName, SetQueueInterface $setQueue)
 * Consumer:   subscribe($queueName, callable $callback, ?callable $failure)
 *             redrive($queueName)
 *             callConsumers($queueName, $count = 1)  — not pump()
 *
 * Library ack: success commit()s; subscriber exception still commit()s after
 * failure handlers (callErrorHandlers). Worker cannot withhold ack by throwing.
 *
 * Worker poll (D17): wrap callConsumers in exponential backoff 0/1/2/…/100ms.
 * Hit → sleep 0. Miss → sleep after the empty pull (0→1ms, then min(100, last*2)).
 */
class PubSubRedisConfig
{
    private const LOCAL_FALLBACK_HOST = '127.0.0.1';

    public static function host(): string
    {
        return trim((string) ($_ENV['REDIS_PUBSUB_HOST'] ?? self::LOCAL_FALLBACK_HOST));
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

    public static function pvhQueueName(): string
    {
        return trim((string) ($_ENV['REDIS_PVH_QUEUE_NAME'] ?? 'amtgard-idp-pvh'));
    }

    public static function dataStructureConfig(): RedisDataStructureConfig
    {
        $config = new RedisDataStructureConfig();
        $config->setConfig([
            'host' => self::resolvedHost(),
            'port' => self::port(),
        ]);

        return $config;
    }

    public static function connect(Redis $redis): Redis
    {
        $lastError = null;

        foreach (self::hostCandidates() as $host) {
            try {
                if (@$redis->pconnect($host, self::port(), 1.0)) {
                    self::selectDatabase($redis);
                    return $redis;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
            }

            $redis = new Redis();
        }

        throw new RedisException(
            sprintf(
                'Redis connection failed for hosts: %s',
                implode(', ', self::hostCandidates())
            ),
            0,
            $lastError
        );
    }

    /**
     * Host passed to set-queue RedisHashSetFactory (uses connect(), not pconnect).
     * When the configured production hostname does not resolve locally, use the fallback
     * without requiring a live Redis probe (unit tests and dev Docker).
     */
    private static function resolvedHost(): string
    {
        $candidates = self::hostCandidates();

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if (self::isReachable($candidates[0])) {
            return $candidates[0];
        }

        if (!self::hostResolves($candidates[0])) {
            return $candidates[1];
        }

        throw new RedisException(
            sprintf(
                'Redis connection failed for hosts: %s',
                implode(', ', $candidates)
            )
        );
    }

    private static function hostResolves(string $host): bool
    {
        if ($host === self::LOCAL_FALLBACK_HOST || $host === 'localhost') {
            return true;
        }

        return gethostbyname($host) !== $host;
    }

    private static function isReachable(string $host): bool
    {
        $redis = new Redis();

        try {
            return @$redis->connect($host, self::port(), 1.0);
        } catch (\Throwable) {
            return false;
        } finally {
            if ($redis->isConnected()) {
                $redis->close();
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function hostCandidates(): array
    {
        $primary = self::host();
        if ($primary === self::LOCAL_FALLBACK_HOST || $primary === 'localhost') {
            return [$primary];
        }

        // Production hostname may not resolve in dev Docker; try local Redis next.
        return [$primary, self::LOCAL_FALLBACK_HOST];
    }

    private static function selectDatabase(Redis $redis): void
    {
        $database = self::database();
        if ($database !== 0) {
            $redis->select($database);
        }
    }
}
