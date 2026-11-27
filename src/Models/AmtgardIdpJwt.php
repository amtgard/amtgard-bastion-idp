<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserJwtGeneration;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\JwksFactory;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\OAuthKeyMaterial;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\Traits\Builder\Builder;
use Optional\Optional;
use Amtgard\Traits\Builder\Getter;
use Firebase\JWT\JWT;
use Lcobucci\JWT\Signer;

final class AmtgardIdpJwt
{
    use Builder;
    use Getter;

    protected AuthorizationJwtAssembler $assembler;

    protected RedisCacheRepository $redisCacheRepository;

    protected IdpLogging $idpLogging;

    /**
     * Signer injected from the container-bound Signer (§5.1a) through the
     * builder (ES256 design §5.4). Null until the builder sets it;
     * buildAuthorizationTokens() fails fast (D7) when it is missing.
     */
    protected ?Signer $signer = null;

    /**
     * Mint fat + compact authorization JWTs from one claim assembly (same `exp` / `pvh`).
     * Signed with the injected Signer; the kid is the RFC 7638 thumbprint of the
     * current public key (ES256 design §5.4, §6).
     *
     * @return array{jwt: string, compact_jwt: string}
     */
    public function buildAuthorizationTokens(
        EntityInterface $user,
        ?string $oauthClientId = null,
        ?int $loginDbId = null
    ): array {
        if ($this->signer === null) {
            throw new \RuntimeException('Authorization JWT signer was not injected.');
        }

        $claims = $this->assembler->buildClaims($user, $oauthClientId, $loginDbId);
        $privateKey = OAuthKeyMaterial::readFromEnv('OAUTH_PRIVATE_KEY');
        $algorithm = $this->signer->algorithmId();
        $kid = JwksFactory::fromPublicKeyPem(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY'))->kid();

        $jwt = JWT::encode($claims, $privateKey, $algorithm, $kid);
        $compactJwt = JWT::encode(
            AuthorizationJwtAssembler::compactClaims($claims),
            $privateKey,
            $algorithm,
            $kid
        );

        $this->idpLogging->oauth()->info('authorization jwt minted', [
            'user_uuid' => (string) $user->userId,
            'aud' => $claims['aud'] ?? null,
        ]);

        Optional::ofNullable($this->assembler->lastGeneration())
            ->ifPresent(function (UserJwtGeneration $generation) use ($user): void {
                $this->redisCacheRepository->setPvhRecord(
                    PvhCacheRecord::fromGeneration($generation, (string) ($user->email ?? ''))
                );
            });

        return ['jwt' => $jwt, 'compact_jwt' => $compactJwt];
    }

    public function buildAuthorizationJwt(
        EntityInterface $user,
        ?string $oauthClientId = null,
        ?int $loginDbId = null
    ): string {
        return $this->buildAuthorizationTokens($user, $oauthClientId, $loginDbId)['jwt'];
    }

    public function validateJwtChallenge(string $jwt): bool
    {
        return $this->assembler->validateJwtChallenge($jwt);
    }
}
