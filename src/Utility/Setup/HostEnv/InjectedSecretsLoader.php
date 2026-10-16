<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Setup\HostEnv;

use Amtgard\Traits\Builder\Builder;
use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Loads host-env-vault render output (secrets.env) into the process environment (Adapter).
 */
final class InjectedSecretsLoader
{
    use Builder;

    private string $secretsDir = '';

    private ?LoggerInterface $logger = null;

    public function secretsDir(string $secretsDir): self
    {
        $this->secretsDir = $secretsDir;

        return $this;
    }

    public function logger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return list<string> entry names applied (keys only, never values)
     */
    public function applyMissing(): array
    {
        $path = rtrim($this->secretsDir, '/') . '/secrets.env';
        if ($this->secretsDir === '' || !is_readable($path)) {
            $this->log(LogLevel::DEBUG, 'host_env_inject_skip', ['reason' => 'missing_secrets_env', 'path' => $path]);

            return [];
        }

        $dir = rtrim($this->secretsDir, '/');
        $candidateKeys = $this->keysDeclaredInFile($path);
        $missingBefore = array_values(array_filter(
            $candidateKeys,
            fn (string $key): bool => !$this->envAlreadySet($key),
        ));
        Dotenv::createMutable($dir, 'secrets.env')->safeLoad();
        $applied = array_values(array_filter(
            $missingBefore,
            fn (string $key): bool => $this->envAlreadySet($key),
        ));
        $applied = [...$applied, ...$this->applyInjectedFilePaths()];

        $this->log(LogLevel::INFO, 'host_env_inject_applied', ['count' => count($applied)]);

        return $applied;
    }

    /**
     * @return list<string> env keys set from inject_files basenames
     */
    private function applyInjectedFilePaths(): array
    {
        if ($this->secretsDir === '') {
            return [];
        }

        /** @var array<string, string> $basenameToEnv */
        $basenameToEnv = [
            'oauth-private.key' => 'OAUTH_PRIVATE_KEY',
            'oauth-public.key' => 'OAUTH_PUBLIC_KEY',
            'apple.p8' => 'APPLE_KEY_FILE_PATH',
        ];

        $applied = [];
        foreach ($basenameToEnv as $basename => $envKey) {
            if ($this->envAlreadySet($envKey)) {
                continue;
            }
            $path = rtrim($this->secretsDir, '/') . '/files/' . $basename;
            if (!is_readable($path)) {
                continue;
            }
            putenv($envKey . '=' . $path);
            $_ENV[$envKey] = $path;
            $applied[] = $envKey;
        }

        return $applied;
    }

    /**
     * @return list<string>
     */
    private function keysDeclaredInFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $keys = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = substr($line, 0, $eq);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function envAlreadySet(string $key): bool
    {
        $fromGetenv = getenv($key);
        if (is_string($fromGetenv) && $fromGetenv !== '') {
            return true;
        }

        $fromSuperglobal = $_ENV[$key] ?? null;

        return is_string($fromSuperglobal) && $fromSuperglobal !== '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        $this->logger?->log($level, $message, $context);
    }
}
