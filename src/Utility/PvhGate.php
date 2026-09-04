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
        $response->getBody()->write(json_encode(['error' => 'stale_token']));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
    }

    public static function staleTokenResponse(): ResponseInterface
    {
        return self::writeStaleToken((new ResponseFactory())->createResponse());
    }
}
