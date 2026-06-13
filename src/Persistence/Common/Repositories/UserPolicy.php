<?php

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IAM\Allowance\Policy;
use Psr\Log\LoggerInterface;
use Throwable;

class UserPolicy
{
    public function __construct(
        private UserPolicyClaimRepository $claimRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getUserPolicy(EntityInterface $user, ?int $forClientDbId = null): Policy
    {
        try {
            return $this->claimRepository->getUserPolicy($user, $forClientDbId);
        } catch (Throwable $e) {
            $this->logger->error('Failed to load user policy; using empty policy', [
                'detail' => $e->getMessage(),
            ]);

            return new Policy([]);
        }
    }

    public function toPolicyJson(EntityInterface $user, ?int $forClientDbId = null): string
    {
        try {
            return $this->getUserPolicy($user, $forClientDbId)->toJson();
        } catch (Throwable $e) {
            $this->logger->error('Failed to encode user policy for JWT; using empty policy', [
                'detail' => $e->getMessage(),
            ]);

            return '[]';
        }
    }
}
