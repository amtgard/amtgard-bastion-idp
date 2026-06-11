<?php

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IAM\Allowance\Policy;

class UserPolicy
{
    public function __construct(private UserPolicyClaimRepository $claimRepository)
    {
    }

    public function getUserPolicy(EntityInterface $user, ?int $forClientDbId = null): Policy
    {
        return $this->claimRepository->getUserPolicy($user, $forClientDbId);
    }
}
