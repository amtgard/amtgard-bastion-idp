<?php

declare(strict_types=1);


namespace Amtgard\IdP\Models;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Firebase\JWT\JWT;

final class AmtgardIdpJwt
{
    use Builder;
    use Getter;

    protected AuthorizationJwtAssembler $assembler;

    protected RedisCacheRepository $redisCacheRepository;

    /**
     * Mint fat + compact RS256 tokens from one claim assembly (same `exp` / `pvh`).
     *
     * @return array{jwt: string, compact_jwt: string}
     */
    public function buildAuthorizationTokens(
        EntityInterface $user,
        ?string $oauthClientId = null,
        ?int $loginDbId = null
    ): array {
        $claims = $this->assembler->buildClaims($user, $oauthClientId, $loginDbId);
        $privateKey = file_get_contents($_ENV['OAUTH_PRIVATE_KEY']);

        $jwt = JWT::encode($claims, $privateKey, 'RS256');
        $compactJwt = JWT::encode(
            AuthorizationJwtAssembler::compactClaims($claims),
            $privateKey,
            'RS256'
        );

        $generation = $this->assembler->lastGeneration();
        if ($generation !== null) {
            $this->redisCacheRepository->setPvhRecord(
                PvhCacheRecord::fromGeneration($generation, (string) ($user->email ?? ''))
            );
        }

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
