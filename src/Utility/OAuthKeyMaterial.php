<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

/**
 * Reads PEM material from env values (file:// URI, filesystem path, or inline PEM).
 */
final class OAuthKeyMaterial
{
    public static function readFromEnv(string $envKey): string
    {
        $configured = $_ENV[$envKey] ?? getenv($envKey);
        if (!is_string($configured) || $configured === '') {
            throw new \RuntimeException("Missing environment value for {$envKey}.");
        }

        if (str_starts_with($configured, 'file://')) {
            $path = substr($configured, 7);
            $contents = file_get_contents($path);

            return $contents !== false ? $contents : throw new \RuntimeException("Unable to read key file {$path}.");
        }

        if (str_starts_with($configured, '-----BEGIN')) {
            return $configured;
        }

        $contents = file_get_contents($configured);

        return $contents !== false ? $contents : throw new \RuntimeException("Unable to read key file {$configured}.");
    }
}
