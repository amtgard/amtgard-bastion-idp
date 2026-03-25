<?php

namespace Amtgard\IdP\Utility;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Optional\Optional;
use Psr\Http\Message\ServerRequestInterface;

class Utility
{
    public static function userIsAuthenticated() {
        return array_key_exists('user_id', $_SESSION);
    }

    public static function dateFrom(\DateInterval $dateInterval): \DateTimeInterface {
        return (new \DateTimeImmutable())->add($dateInterval);
    }

    public static function getBearerJwt(ServerRequestInterface $request): ?string {
        $authHeader = $request->getHeaderLine('Authorization');

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function validateJwtClaims(string $challengeJwt, string $userJwt): bool
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

            $challengePolicy = json_decode($challengePolicyJson, true);
            $userDataPolicy = json_decode($userDataPolicyJson, true);

            // Check if JSON is valid and is an array
            if (!is_array($challengePolicy) || !is_array($userDataPolicy)) {
                return false;
            }

            // Normalize by sorting and compare
            sort($challengePolicy);
            sort($userDataPolicy);

            if ($challengePolicy !== $userDataPolicy) {
                return false;
            }
        }

        return true;
    }

    public static function validateJwtSignature(string $putativeJwt): ?string {
        try {
            $config = Configuration::forAsymmetricSigner(
                new Sha256(),
                InMemory::file($_ENV['OAUTH_PRIVATE_KEY']),
                InMemory::file($_ENV['OAUTH_PUBLIC_KEY'])
            );

            $token = $config->parser()->parse($putativeJwt);

            $clock = new SystemClock(new \DateTimeZone("UTC"));
            $constraints = [
                new SignedWith($config->signer(), $config->verificationKey()),
                new LooseValidAt($clock)
            ];

            if ($config->validator()->validate($token, ...$constraints)) {
                return $putativeJwt;
            }

        } catch (\Exception $e) {
            return null;
        }
    }

    public static function validateJwtRequest(ServerRequestInterface $request): ?string {
        $optionalJwt = Optional::ofNullable(self::getBearerJwt($request));
        if ($optionalJwt->isPresent()) {
            $putativeJwt = $optionalJwt->get();
            return self::validateJwtSignature($putativeJwt);
        }
        return null;
    }

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

    public static function getAuthenticatedUser(): ?UserEntity {
        if (!self::userIsAuthenticated()) {
            return null;
        }

        $userRepo = EntityManager::getManager()->getRepository(UserRepository::class);
        /** @var OAuthUser $user */
        $user = $userRepo->getUserEntityById($_SESSION['user_id']);
        return Optional::ofNullable($user)
            ->map(fn($u) => $u->getUserEntity())
            ->orElse(null);
    }
}
