<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Optional\Optional;

final class HttpBasicCredentials
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
    ) {}
}

final class HttpBasicCredentialsParser
{
    public static function fromAuthorizationHeader(string $authorizationHeader): Optional
    {
        $payload = self::matchBasicPayload($authorizationHeader);
        if ($payload === null) {
            return Optional::blank();
        }

        return self::decodePayload($payload);
    }

    private static function matchBasicPayload(string $authorizationHeader): ?string
    {
        if (!preg_match('/^Basic\s+(\S+)$/i', $authorizationHeader, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private static function decodePayload(string $payload): Optional
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return Optional::blank();
        }

        [$clientId, $clientSecret] = explode(':', $decoded, 2);

        return Optional::of(new HttpBasicCredentials($clientId, $clientSecret));
    }
}
