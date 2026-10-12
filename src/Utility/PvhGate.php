<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IdP\Utility\Log\IdpLoggingRuntime;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Shared pvh compare for validate, userinfo middleware, and client-restricted Bearer.
 * Current → proceed; previous → 409 stale_token; unknown → 401; miss → seed (validate and auth middleware).
 */
final class PvhGate
{
    public static function missSeedRecord(
        string $userUuid,
        string $aud,
        string $email,
        ?string $presentedPvh,
        ?string $fatPolicyHash
    ): PvhCacheRecord {
        $seedPvh = $presentedPvh ?? Pvh::encode((int) floor(microtime(true) * 1000), $fatPolicyHash);

        IdpLoggingRuntime::pvh()->debug('pvh gate miss seed record built', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
        ]);

        return new PvhCacheRecord($userUuid, $aud, $email, $seedPvh, null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function evaluate(
        ?PvhCacheRecord $cached,
        array $payload,
        ?LoggerInterface $logger = null,
    ): PvhAccess {
        $pvhContext = Jwt::presentedPvhContext($payload);

        return self::evaluatePresented(
            $cached,
            $pvhContext['presented'],
            $pvhContext['fatPolicyHash'],
            $logger,
        );
    }

    public static function evaluatePresented(
        ?PvhCacheRecord $cached,
        ?string $presentedPvh,
        ?string $fatPolicyHash,
        ?LoggerInterface $logger = null,
    ): PvhAccess {
        if ($cached === null) {
            $logger?->debug('pvh gate evaluate', ['access' => PvhAccess::Miss->name]);

            return PvhAccess::Miss;
        }

        if (self::presentedMatchesCache($presentedPvh, $fatPolicyHash, $cached->getPvh())) {
            $logger?->debug('pvh gate evaluate', ['access' => PvhAccess::Current->name]);

            return PvhAccess::Current;
        }

        $prevPvh = $cached->getPrevPvh();
        if ($prevPvh !== null && self::presentedMatchesCache($presentedPvh, $fatPolicyHash, $prevPvh)) {
            $logger?->debug('pvh gate evaluate', ['access' => PvhAccess::Previous->name]);

            return PvhAccess::Previous;
        }

        $logger?->debug('pvh gate evaluate', ['access' => PvhAccess::Unknown->name]);

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
        return JsonResponseBody::writeError($response, $error, $status);
    }
}
