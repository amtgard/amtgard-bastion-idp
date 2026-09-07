<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Shared pvh compare for validate, userinfo middleware, and client-restricted Bearer.
 * Current → proceed; previous → 409 stale_token; unknown/miss → 401 (validate seeds miss itself).
 */
final class PvhGate
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function evaluate(?PvhCacheRecord $cached, array $payload): PvhAccess
    {
        $presentedPvh = Jwt::presentedPvhClaim($payload);
        $fatPolicyHash = $presentedPvh === null ? Jwt::policyHashFromFatClaims($payload) : null;

        return self::evaluatePresented($cached, $presentedPvh, $fatPolicyHash);
    }

    public static function evaluatePresented(
        ?PvhCacheRecord $cached,
        ?string $presentedPvh,
        ?string $fatPolicyHash
    ): PvhAccess {
        if ($cached === null) {
            return PvhAccess::Miss;
        }

        if (self::presentedMatchesCache($presentedPvh, $fatPolicyHash, $cached->getPvh())) {
            return PvhAccess::Current;
        }

        $prevPvh = $cached->getPrevPvh();
        if ($prevPvh !== null && self::presentedMatchesCache($presentedPvh, $fatPolicyHash, $prevPvh)) {
            return PvhAccess::Previous;
        }

        return PvhAccess::Unknown;
    }

    public static function presentedMatchesCache(?string $presentedPvh, ?string $fatPolicyHash, string $cachedPvh): bool
    {
        if ($presentedPvh !== null) {
            return hash_equals($cachedPvh, $presentedPvh);
        }

        if ($fatPolicyHash === null || !Pvh::isPvhHex($cachedPvh)) {
            return false;
        }

        $presentedPrefix = bin2hex(substr($fatPolicyHash, 0, Pvh::HASH_PREFIX_BYTE_LENGTH));

        return hash_equals(Pvh::hashPrefixHex($cachedPvh), $presentedPrefix);
    }

    public static function writeStaleToken(ResponseInterface $response): ResponseInterface
    {
        return self::writeJsonError($response, 'stale_token', 409);
    }

    public static function writeUnauthorized(ResponseInterface $response): ResponseInterface
    {
        return self::writeJsonError($response, 'unauthorized', 401);
    }

    public static function staleTokenResponse(): ResponseInterface
    {
        return self::writeStaleToken((new ResponseFactory())->createResponse());
    }

    private static function writeJsonError(ResponseInterface $response, string $error, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => $error]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
