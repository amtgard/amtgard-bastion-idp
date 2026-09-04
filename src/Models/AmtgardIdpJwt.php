<?php

namespace Amtgard\IdP\Models;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Amtgard\IdP\Persistence\Common\Repositories\JwtChallenge;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;

class AmtgardIdpJwt
{
    private AuthorizationJwtAssembler $assembler;
    private RedisCacheRepository $redisCacheRepository;

    public function __construct(
        UserPolicy $userPolicy,
        JwtChallenge $jwtChallenge,
        ClientRepository $clientRepository,
        UserLoginClientRepository $metadataRepository,
        UserLoginRepository $userLoginRepository,
        LoggerInterface $logger,
        UserJwtGenerationRepository $generationRepository,
        RedisCacheRepository $redisCacheRepository,
    ) {
        $this->assembler = new AuthorizationJwtAssembler(
            $userPolicy,
            $jwtChallenge,
            $clientRepository,
            $metadataRepository,
            $userLoginRepository,
            $logger,
            $generationRepository,
        );
        $this->redisCacheRepository = $redisCacheRepository;
    }

    public function buildAuthorizationJwt(
        EntityInterface $user,
        ?string $oauthClientId = null,
        ?int $loginDbId = null
    ): string {
        $claims = $this->assembler->buildClaims($user, $oauthClientId, $loginDbId);
        $privateKey = file_get_contents($_ENV['OAUTH_PRIVATE_KEY']);

        $jwt = JWT::encode($claims, $privateKey, 'RS256');

        $generation = $this->assembler->lastGeneration();
        if ($generation !== null) {
            $this->redisCacheRepository->setPvhRecord(new PvhCacheRecord(
                $generation->getUserUuid(),
                $generation->getAud(),
                (string) ($user->email ?? ''),
                $generation->getPvh(),
                $generation->getPrevPvh(),
            ));
        }

        return $jwt;
    }

    public function validateJwtChallenge(string $jwt): bool
    {
        return $this->assembler->validateJwtChallenge($jwt);
    }
}
