<?php

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\OrkService;
use Amtgard\IdP\Models\Orn\IdpRequirement;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;

class UserAuthority
{
    private UserPolicy $userPolicy;
    public function __construct(UserPolicy $userPolicy) {
        $this->userPolicy = $userPolicy;
    }
    public function isAdmin(UserEntity $user) {
        $policy = $this->userPolicy->getUserPolicy($user);
        $requirement = new IdpRequirement(OrkService::Idp, "Idp:0::::IDP/EditClient");

        return $policy->grants($requirement);
    }
}