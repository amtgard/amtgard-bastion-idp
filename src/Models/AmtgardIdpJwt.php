<?php

namespace Amtgard\IdP\Models;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Amtgard\IdP\Persistence\Common\Repositories\JwtChallenge;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Firebase\JWT\JWT;

class AmtgardIdpJwt
{
    public function __construct(
        private UserPolicy $userPolicy,
        private JwtChallenge $jwtChallenge,
        private ClientRepository $clientRepository,
        private UserLoginClientRepository $metadataRepository,
        private UserLoginRepository $userLoginRepository,
    ) {}

    public function buildAuthorizationJwt(
        EntityInterface $user,
        ?string $oauthClientId = null,
        ?int $loginDbId = null
    ): string {
        $audience = $oauthClientId ?? ($_SESSION['client_id'] ?? null);
        $forClientDbId = null;

        if ($audience !== null && $audience !== '') {
            $client = $this->clientRepository->findClientByIdentifier($audience);
            if ($client !== null) {
                $forClientDbId = $client->getId();
                OrnClaimRegistry::registerForClient($client);
            }
        }

        $policyJson = $this->userPolicy->getUserPolicy($user, $forClientDbId)->toJson();
        $challenge = $this->jwtChallenge->createChallenge($user);
        $privateKey = file_get_contents($_ENV['OAUTH_PRIVATE_KEY']);

        $loginDbId ??= LoginSession::getLoginId();
        if ($loginDbId === null) {
            $loginDbId = $this->userLoginRepository->resolveDefaultLoginIdForUser((int) $user->id);
        }

        $claims = [
            'iat' => time(),
            'sub' => $user->userId,
            'iss' => 'https://idp.amtgard.com',
            'orkid' => $user->orkUserId,
            'orkuser' => $user->username,
            'email' => $user->email,
            'policy' => $policyJson,
            'challenge' => $challenge,
            'exp' => time() + 3600,
        ];

        if ($audience !== null && $audience !== '') {
            $claims['aud'] = $audience;
            if ($forClientDbId !== null && $loginDbId !== null) {
                $metadata = $this->metadataRepository->getMetadataForJwt($loginDbId, $forClientDbId);
                if ($metadata !== null) {
                    $claims['client_metadata'] = $metadata;
                }
            }
        }

        return JWT::encode($claims, $privateKey, 'RS256');
    }

    public function validateJwtChallenge(string $jwt): bool
    {
        return $this->jwtChallenge->validateChallenge($jwt);
    }
}
