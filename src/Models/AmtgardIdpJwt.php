<?php

namespace Amtgard\IdP\Models;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Amtgard\IdP\Persistence\Common\Repositories\JwtChallenge;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Firebase\JWT\JWT;
use Optional\Optional;

class AmtgardIdpJwt
{
    private AuthorizationJwtAssembler $assembler;

    public function __construct(
        UserPolicy $userPolicy,
        JwtChallenge $jwtChallenge,
        ClientRepository $clientRepository,
        UserLoginClientRepository $metadataRepository,
        UserLoginRepository $userLoginRepository,
    ) {
        $this->assembler = new AuthorizationJwtAssembler(
            $userPolicy,
            $jwtChallenge,
            $clientRepository,
            $metadataRepository,
            $userLoginRepository,
        );
    }

    public function buildAuthorizationJwt(
        EntityInterface $user,
        ?string $oauthClientId = null,
        ?int $loginDbId = null
    ): string {
        $claims = $this->assembler->buildClaims($user, $oauthClientId, $loginDbId);
        $privateKey = file_get_contents($_ENV['OAUTH_PRIVATE_KEY']);

        return JWT::encode($claims, $privateKey, 'RS256');
    }

    public function validateJwtChallenge(string $jwt): bool
    {
        return $this->assembler->validateJwtChallenge($jwt);
    }
}
