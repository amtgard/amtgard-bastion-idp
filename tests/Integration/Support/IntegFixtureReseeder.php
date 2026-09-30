<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration\Support;

/** Resets integ DB fixtures (seed.php) and session Redis between test methods. */
final class IntegFixtureReseeder
{
    private const DEFAULT_APP_CONTAINER = 'amtgard-idp';

    private const DEFAULT_SESSIONS_CONTAINER = 'amtgard-idp-sessions-integ';

    /** Host-side PDO targets published integ MariaDB (see phpunit.integ.xml). */
    private const INTEG_DB_ENV = [
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '36307',
        'DB_NAME' => 'idp',
        'DB_USER' => 'idp',
        'DB_PASS' => 'secret',
    ];

    public static function reseedForTest(): void
    {
        self::flushIntegSessionRedis();
        self::runSeedScript();
    }

    private static function runSeedScript(): void
    {
        if (self::dockerAvailable()) {
            self::runSeedViaDockerExec();

            return;
        }

        self::runSeedViaHostPhp();
    }

    private static function runSeedViaDockerExec(): void
    {
        $appContainer = (string) (getenv('APP_CONTAINER') ?: self::DEFAULT_APP_CONTAINER);
        $command = sprintf(
            'docker exec %s bash -lc %s 2>&1',
            escapeshellarg($appContainer),
            escapeshellarg('cd /var/www/idp.amtgard.com && php tests/Integration/seed.php'),
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Integ seed failed (exit ' . $exitCode . "):\n" . implode("\n", $output),
            );
        }
    }

    private static function runSeedViaHostPhp(): void
    {
        $root = dirname(__DIR__, 3);
        $seedScript = $root . '/tests/Integration/seed.php';
        if (!is_file($seedScript)) {
            throw new \RuntimeException('Missing integ seed script: ' . $seedScript);
        }

        $env = self::environmentForHostSeed();
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($seedScript);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, $root, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start integ seed subprocess');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Integ seed failed (exit ' . $exitCode . "):\n"
                . trim($stderr . "\n" . $stdout),
            );
        }
    }

    /** @return array<string, string> */
    private static function environmentForHostSeed(): array
    {
        $path = getenv('PATH');
        $merged = is_string($path) && $path !== '' ? ['PATH' => $path] : [];

        $merged['ENVIRONMENT'] = 'DEV_INTEG';
        foreach (self::INTEG_DB_ENV as $key => $default) {
            $fromEnv = getenv($key);
            $merged[$key] = ($fromEnv !== false && $fromEnv !== '') ? $fromEnv : $default;
        }

        return $merged;
    }

    private static function flushIntegSessionRedis(): void
    {
        if (!self::dockerAvailable()) {
            return;
        }

        $container = (string) (getenv('INTEG_SESSIONS_CONTAINER') ?: self::DEFAULT_SESSIONS_CONTAINER);
        $redisDb = (string) (getenv('SESSION_REDIS_DB') ?: '1');
        $command = sprintf(
            'docker exec %s redis-cli -n %s FLUSHDB 2>&1',
            escapeshellarg($container),
            escapeshellarg($redisDb),
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Integ session Redis flush failed: ' . implode("\n", $output),
            );
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
