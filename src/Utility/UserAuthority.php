<?php

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IdP\Models\Orn\IdpRequirement;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Psr\Log\LoggerInterface;
use Throwable;

class UserAuthority
{
    public function __construct(
        private UserPolicy $userPolicy,
        private LoggerInterface $logger,
    ) {
    }

    public function isAdmin(UserEntity $user): bool
    {
        try {
            $policy = $this->userPolicy->getUserPolicy($user);
            $requirement = new IdpRequirement(ServiceCatalog::Idp, "Idp:0::::IDP/EditClient");

            return $policy->isAuthorized($requirement);
        } catch (Throwable $e) {
            $this->logger->error('Failed to evaluate admin policy; treating user as non-admin', [
                'detail' => $e->getMessage(),
            ]);

            return false;
        }
    }
}