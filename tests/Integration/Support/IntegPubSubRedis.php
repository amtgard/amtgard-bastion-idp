<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration\Support;

/** Read PVH JSON records from integ pub/sub Redis (host-side via docker exec). */
final class IntegPubSubRedis
{
    private const DEFAULT_SESSIONS_CONTAINER = 'amtgard-idp-sessions-integ';

    public static function pvhRecordJson(string $userUuid, string $aud): ?string
    {
        if (!self::dockerAvailable()) {
            return null;
        }

        $container = (string) (getenv('INTEG_SESSIONS_CONTAINER') ?: self::DEFAULT_SESSIONS_CONTAINER);
        $redisDb = (string) (getenv('REDIS_PUBSUB_DB') ?: '0');
        $key = 'pvh:' . $userUuid . ':' . $aud;
        $command = sprintf(
            'docker exec %s redis-cli -n %s GET %s 2>&1',
            escapeshellarg($container),
            escapeshellarg($redisDb),
            escapeshellarg($key),
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Integ pub/sub Redis GET failed: ' . implode("\n", $output));
        }

        $line = trim(implode("\n", $output));
        if ($line === '' || strcasecmp($line, '(nil)') === 0) {
            return null;
        }

        return $line;
    }

    public static function flushPubSubDatabase(): void
    {
        if (!self::dockerAvailable()) {
            return;
        }

        $container = (string) (getenv('INTEG_SESSIONS_CONTAINER') ?: self::DEFAULT_SESSIONS_CONTAINER);
        $redisDb = (string) (getenv('REDIS_PUBSUB_DB') ?: '0');
        $command = sprintf(
            'docker exec %s redis-cli -n %s FLUSHDB 2>&1',
            escapeshellarg($container),
            escapeshellarg($redisDb),
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Integ pub/sub Redis flush failed: ' . implode("\n", $output));
        }
    }

    private static function dockerAvailable(): bool
    {
        $output = [];
        $exitCode = 0;
        exec('docker info 2>/dev/null', $output, $exitCode);

        return $exitCode === 0;
    }
}
