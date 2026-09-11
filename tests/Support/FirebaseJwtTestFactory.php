<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

use Firebase\JWT\JWT as FirebaseJwt;
use RuntimeException;

/**
 * RS256 JWT builder for tests using firebase/php-jwt (dev-keys under /tmp).
 */
final class FirebaseJwtTestFactory
{
    public const DEFAULT_PRIVATE_KEY_PATH = '/tmp/private.key';

    public const DEFAULT_PUBLIC_KEY_PATH = '/tmp/public.key';

    public const DEFAULT_ISSUER = 'http://localhost';

    public static function ensureKeys(
        string $privateKeyPath = self::DEFAULT_PRIVATE_KEY_PATH,
        string $publicKeyPath = self::DEFAULT_PUBLIC_KEY_PATH,
    ): void {
        OAuthTestEnvironment::restorePhpUnitOAuthKeys();

        $devKeysDir = dirname(__DIR__, 2) . '/dev-keys';
        if (!file_exists($privateKeyPath) && file_exists($devKeysDir . '/private.key')) {
            @copy($devKeysDir . '/private.key', $privateKeyPath);
        }
        if (!file_exists($publicKeyPath) && file_exists($devKeysDir . '/public.key')) {
            @copy($devKeysDir . '/public.key', $publicKeyPath);
        }
    }

    public static function assertKeysAvailable(
        string $privateKeyPath = self::DEFAULT_PRIVATE_KEY_PATH,
        string $publicKeyPath = self::DEFAULT_PUBLIC_KEY_PATH,
    ): void {
        if (!file_exists($privateKeyPath) || !file_exists($publicKeyPath)) {
            throw new RuntimeException('dev-keys were not copied to /tmp for JWT signing');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function encode(
        array $claims,
        string $privateKeyPath = self::DEFAULT_PRIVATE_KEY_PATH,
        string $publicKeyPath = self::DEFAULT_PUBLIC_KEY_PATH,
    ): string {
        self::assertKeysAvailable($privateKeyPath, $publicKeyPath);
        $privateKey = file_get_contents($privateKeyPath);
        if ($privateKey === false) {
            throw new RuntimeException('Unable to read private key for JWT signing');
        }

        return FirebaseJwt::encode($claims, $privateKey, 'RS256');
    }

    public static function authorizationForUserAndClient(
        string $userId,
        string $clientId,
        ?string $pvh = null,
        ?string $policy = null,
        string $issuer = self::DEFAULT_ISSUER,
        int $ttlSeconds = 3600,
    ): string {
        $now = time();
        $claims = [
            'iss' => $issuer,
            'aud' => $clientId,
            'sub' => $userId,
            'exp' => $now + $ttlSeconds,
        ];
        if ($pvh !== null) {
            $claims['pvh'] = $pvh;
        }
        if ($policy !== null) {
            $claims['policy'] = $policy;
        }

        return self::encode($claims);
    }

    public static function oauthAccessTokenForUserAndClient(
        string $userId,
        string $clientId,
        int $ttlSeconds = 3600,
        string $jti = 'jti-access-token',
    ): string {
        $now = time();

        return self::encode(
            [
            'aud' => $clientId,
            'jti' => $jti,
            'sub' => $userId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'scopes' => ['profile', 'email'],
            ]
        );
    }

    public static function lowLatencyAuthorization(
        string $audience,
        string $subject,
        string $email,
        string $policy,
        ?string $pvh = null,
        string $issuer = self::DEFAULT_ISSUER,
        bool $expired = false,
    ): string {
        $now = time();
        $ttlSeconds = 3600;
        $claims = [
            'iss' => $issuer,
            'aud' => $audience,
            'exp' => $expired ? $now - $ttlSeconds : $now + $ttlSeconds,
            'email' => $email,
            'policy' => $policy,
        ];
        if ($subject !== '') {
            $claims['sub'] = $subject;
        }
        if ($pvh !== null) {
            $claims['pvh'] = $pvh;
        }

        return self::encode($claims);
    }

    public static function compactAuthorizationWithPvh(
        string $audience,
        string $subject,
        string $pvh,
        string $issuer = self::DEFAULT_ISSUER,
        int $ttlSeconds = 3600,
    ): string {
        $now = time();

        return self::encode(
            [
            'iss' => $issuer,
            'aud' => $audience,
            'sub' => $subject,
            'exp' => $now + $ttlSeconds,
            'pvh' => $pvh,
            ]
        );
    }
}
