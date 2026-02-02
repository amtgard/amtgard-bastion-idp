<?php

namespace Amtgard\IdP\Utility;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
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

    public static function validateJwt(ServerRequestInterface $request): ?string {
        $authHeader = $request->getHeaderLine('Authorization');

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $putativeJwt = $matches[1];
            
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
        return null;
    }

    public static function parseJwt(string $putativeJwt): ?array {
        // Remove Bearer prefix if present for parsing
        $jwt = $putativeJwt;
        if (preg_match('/Bearer\s+(.*)$/i', $putativeJwt, $matches)) {
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
