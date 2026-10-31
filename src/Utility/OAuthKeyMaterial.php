<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IdP\Utility\Log\IdpLoggingRuntime;

/**
 * Reads PEM material from env values (file:// URI, filesystem path, or inline PEM).
 */
final class OAuthKeyMaterial
{
    public static function pathFromEnv(string $envKey): string
    {
        $configured = self::configuredValue($envKey);
        if ($configured === '') {
            IdpLoggingRuntime::oauth()->emergency('oauth key material missing', [
                'env_key' => $envKey,
            ]);
            throw new \RuntimeException("Missing environment value for {$envKey}.");
        }

        if (str_starts_with($configured, 'file://')) {
            return substr($configured, 7);
        }

        if (str_starts_with($configured, '-----BEGIN')) {
            throw new \RuntimeException("{$envKey} must be a filesystem path for CryptKey, not inline PEM.");
        }

        return $configured;
    }

    public static function readFromEnv(string $envKey): string
    {
        $configured = self::configuredValue($envKey);
        if (!is_string($configured) || $configured === '') {
            IdpLoggingRuntime::oauth()->emergency('oauth key material missing', [
                'env_key' => $envKey,
            ]);
            throw new \RuntimeException("Missing environment value for {$envKey}.");
        }

        if (str_starts_with($configured, 'file://')) {
            $path = substr($configured, 7);
            $contents = file_get_contents($path);

            if ($contents === false) {
                IdpLoggingRuntime::oauth()->emergency('oauth key material unreadable', [
                    'env_key' => $envKey,
                ]);
            }

            return $contents !== false ? $contents : throw new \RuntimeException("Unable to read key file {$path}.");
        }

        if (str_starts_with($configured, '-----BEGIN')) {
            return $configured;
        }

        $contents = file_get_contents($configured);

        if ($contents === false) {
            IdpLoggingRuntime::oauth()->emergency('oauth key material unreadable', [
                'env_key' => $envKey,
            ]);
        }

        return $contents !== false ? $contents : throw new \RuntimeException("Unable to read key file {$configured}.");
    }

    private static function configuredValue(string $envKey): string
    {
        $fromEnv = $_ENV[$envKey] ?? getenv($envKey);

        return is_string($fromEnv) ? $fromEnv : '';
    }
}
