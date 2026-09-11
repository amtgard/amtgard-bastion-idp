<?php

declare(strict_types=1);


namespace Amtgard\IdP\Utility;

use Amtgard\IAM\PolicyFactory;
use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use Optional\Optional;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class Jwt
{

    public static function getBearerJwt(ServerRequestInterface $request): ?string {
        $authHeader = $request->getHeaderLine('Authorization');

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function validateJwt(string $challengeJwt, string $userJwt): bool
    {
        $challenge = self::parseJwt($challengeJwt);
        $userData = self::parseJwt($userJwt);

        if (empty($challenge) || empty($userData)) {
            return false;
        }

        // 1. `aud` must match if present in either
        if (($challenge['aud'] ?? null) !== ($userData['aud'] ?? null)) {
            return false;
        }

        // 2. `iss` must match if present in either
        if (($challenge['iss'] ?? null) !== ($userData['iss'] ?? null)) {
            return false;
        }

        // 3. `exp` in the challenge JWT must be a future timestamp
        if (!isset($challenge['exp']) || !is_numeric($challenge['exp']) || $challenge['exp'] <= time()) {
            return false;
        }

        // 4. `policy` fields must contain identical claims
        $challengePolicyJson = $challenge['policy'] ?? null;
        $userDataPolicyJson = $userData['policy'] ?? null;

        if ($challengePolicyJson !== $userDataPolicyJson) {
            if ($challengePolicyJson === null || $userDataPolicyJson === null) {
                // One is null, the other is not, so they don't match.
                return false;
            }

            $challengePolicy = PolicyFactory::fromOrn(json_decode($challengePolicyJson, true));
            $userDataPolicy = PolicyFactory::fromOrn(json_decode($userDataPolicyJson, true));

            if (!$userDataPolicy->is($challengePolicy)) {
                return false;
            }
        }

        return true;
    }

    public static function validateJwtSignature(string $putativeJwt, ?LoggerInterface $logger = null): ?string {
        $failureReason = null;
        try {
            $publicKey = file_get_contents($_ENV['OAUTH_PUBLIC_KEY']);
            FirebaseJwt::decode($putativeJwt, new Key($publicKey, 'RS256'));
        } catch (\Throwable $e) {
            $failureReason = $e->getMessage();
        }

        if ($failureReason !== null) {
            $logger?->debug('jwt signature validation failed', [
                'reason' => $failureReason,
            ]);

            return null;
        }

        return $putativeJwt;
    }

    public static function validateJwtRequest(
        ServerRequestInterface $request,
        ?LoggerInterface $logger = null,
    ): ?string {
        $optionalJwt = Optional::ofNullable(self::getBearerJwt($request));
        if ($optionalJwt->isPresent()) {
            $putativeJwt = $optionalJwt->get();

            return self::validateJwtSignature($putativeJwt, $logger);
        }

        return null;
    }

    /**
     * Payload extraction without signature verification (used for challenge compare and post-verify reads).
     * Verified Bearer tokens should use validateJwtSignature before trusting claims.
     */
    public static function parseJwt(string $jwt): ?array {
        // Remove Bearer prefix if present for parsing
        if (preg_match('/Bearer\s+(.*)$/i', $jwt, $matches)) {
            $jwt = $matches[1];
        }

        $tokenParts = explode('.', $jwt);

        if (count($tokenParts) === 3) {
            return json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1])), true);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function emailClaim(array $payload): string
    {
        $email = $payload['email'] ?? null;

        return is_string($email) ? $email : '';
    }

    /**
     * Authorization JWT (fat or compact) vs a League OAuth access token JWT.
     * Access tokens share the RS256 keys but have no `pvh` and no `policy`.
     *
     * @param array<string, mixed> $payload
     */
    public static function isAuthorizationPayload(array $payload): bool
    {
        return self::presentedPvhClaim($payload) !== null
            || self::policyHashFromFatClaims($payload) !== null;
    }

    /**
     * Presented compact/fat `pvh` claim when it is 44-char hex. Otherwise null (use fat-claim hash).
     *
     * @param array<string, mixed> $payload
     */
    public static function presentedPvhClaim(array $payload): ?string
    {
        $pvh = $payload['pvh'] ?? null;
        if (!is_string($pvh) || !Pvh::isPvhHex($pvh)) {
            return null;
        }

        return $pvh;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{presented: ?string, fatPolicyHash: ?string}
     */
    public static function presentedPvhContext(array $payload): array
    {
        $presented = self::presentedPvhClaim($payload);

        return [
            'presented' => $presented,
            'fatPolicyHash' => self::fatPolicyHashForPresentedContext($presented, $payload),
        ];
    }

    /**
     * 32 raw-byte policy_hash from fat JWT claims. Null when aud or policy JSON is missing.
     * Does not include a `pvh` claim in the hash.
     *
     * @param array<string, mixed> $payload
     */
    public static function policyHashFromFatClaims(array $payload): ?string
    {
        $aud = $payload['aud'] ?? null;
        $policyJson = $payload['policy'] ?? null;
        if (!is_string($aud) || $aud === '' || !is_string($policyJson)) {
            return null;
        }

        return Pvh::policyHash($aud, $policyJson, Pvh::canonicalMetadata($payload['client_metadata'] ?? null));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function fatPolicyHashForPresentedContext(?string $presentedPvh, array $payload): ?string
    {
        if ($presentedPvh !== null) {
            return null;
        }

        return self::policyHashFromFatClaims($payload);
    }

}
