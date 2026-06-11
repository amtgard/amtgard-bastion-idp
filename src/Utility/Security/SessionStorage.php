<?php
declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Optional\Optional;
use Redis;

/**
 * Configures PHP session persistence. When SESSION_REDIS_HOST is set, sessions
 * are stored in Redis so blue-green deploys do not log users out.
 */
class SessionStorage
{
    public static function configure(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $host = self::redisHost();
        if ($host === '') {
            return;
        }

        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', self::redisSavePath($host));
    }

    /**
     * Starts the PHP session, falling back to file storage when Redis is configured
     * but unreachable (common when .env still lists the production hostname).
     */
    public static function startSession(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        self::configure();

        if (self::usesRedisHandler() && !self::redisBackendReachable()) {
            self::useFileHandler();
        }

        return session_start();
    }

    private static function redisHost(): string
    {
        return trim((string) ($_ENV['SESSION_REDIS_HOST'] ?? ''));
    }

    private static function redisPort(): int
    {
        return (int) trim((string) ($_ENV['SESSION_REDIS_PORT'] ?? '6379'));
    }

    private static function redisDatabase(): int
    {
        return (int) trim((string) ($_ENV['SESSION_REDIS_DB'] ?? '1'));
    }

    private static function redisPrefix(): string
    {
        return (string) ($_ENV['SESSION_REDIS_PREFIX'] ?? 'PHPSESS:');
    }

    private static function redisSavePath(string $host): string
    {
        return sprintf(
            'tcp://%s:%d?database=%d&prefix=%s',
            $host,
            self::redisPort(),
            self::redisDatabase(),
            self::redisPrefix()
        );
    }

    private static function usesRedisHandler(): bool
    {
        return ini_get('session.save_handler') === 'redis' && self::redisHost() !== '';
    }

    private static function redisBackendReachable(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }

        $host = self::redisHost();

        try {
            $redis = new Redis();
            $connected = @$redis->connect($host, self::redisPort(), 1.0);
            if (!$connected) {
                return false;
            }

            Optional::ofNullable(self::redisDatabase())
                ->filter(fn (int $database) => $database !== 0)
                ->ifPresent(fn (int $database) => $redis->select($database));

            $redis->close();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function useFileHandler(): void
    {
        // Keeps local dev usable when SESSION_REDIS_HOST targets prod-only DNS names.
        ini_set('session.save_handler', 'files');
        ini_set('session.save_path', sys_get_temp_dir());
    }
}
