<?php
declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

/**
 * Configures PHP session persistence. When SESSION_REDIS_HOST is set, sessions
 * are stored in the shared Redis container so blue-green deploys do not log users out.
 */
class SessionStorage
{
    public static function configure(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $host = trim((string) ($_ENV['SESSION_REDIS_HOST'] ?? ''));
        if ($host === '') {
            return;
        }

        $port = trim((string) ($_ENV['SESSION_REDIS_PORT'] ?? '6379'));
        $database = trim((string) ($_ENV['SESSION_REDIS_DB'] ?? '1'));
        $prefix = (string) ($_ENV['SESSION_REDIS_PREFIX'] ?? 'PHPSESS:');

        ini_set('session.save_handler', 'redis');
        ini_set(
            'session.save_path',
            sprintf('tcp://%s:%s?database=%s&prefix=%s', $host, $port, $database, $prefix)
        );
    }
}
