<?php

namespace Amtgard\IdP\Models;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IdP\Persistence\Common\Repositories\JwtChallenge;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Firebase\JWT\JWT;

class AmtgardIdpJwt
{
    private UserPolicy $userPolicy;
    private JwtChallenge $jwtChallenge;

    public function __construct(UserPolicy $userPolicy, JwtChallenge $jwtChallenge) {
        $this->userPolicy = $userPolicy;
        $this->jwtChallenge = $jwtChallenge;
    }

    public function buildSingleUseJwt(EntityInterface $user, $requesterJwtKey): string {
        $policyJson = $this->userPolicy->getUserPolicy($user)->toJson();
        $challenge = $this->jwtChallenge->createChallenge($user);
        return JWT::encode([
            'iat' => time(),
            'sub' => $user->userId,
            'iss' => "https://idp.amtgard.com",
            'orkid' => $user->orkUserId,
            'orkuser' => $user->username,
            'email' => $user->email,
            'policy' => $policyJson,
            'challenge' => $challenge,
            'exp' => time() + 120
        ], empty($requesterJwtKey) ? $_ENV['JWT_KEY'] : $requesterJwtKey, 'HS512');
    }

    public function validateJwtChallenge(JWT $jwt): bool {
        return $this->jwtChallenge->validateChallenge($jwt);
    }
}