<?php

declare(strict_types=1);


namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IAM\Allowance\Policy;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Throwable;

class UserPolicy
{
    public function __construct(
        private UserPolicyClaimRepository $claimRepository,
        private IdpLogging $idpLogging,
    ) {
    }

    public function getUserPolicy(EntityInterface $user, ?int $forClientDbId = null): Policy
    {
        try {
            return $this->claimRepository->getUserPolicy($user, $forClientDbId);
        } catch (Throwable $e) {
            $this->idpLogging->app()->error('user policy empty fallback', [
                'user_id' => $this->resolveUserDbId($user),
                'fallback' => 'empty_policy',
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
            $this->idpLogging->app()->error('user policy encode empty fallback', [
                'user_id' => $this->resolveUserDbId($user),
                'detail' => $e->getMessage(),
            ]);

            return '[]';
        }
    }

    private function resolveUserDbId(EntityInterface $user): int
    {
        if ($user instanceof RepositoryEntity) {
            return (int) $user->getInternalEntity()->id;
        }

        return (int) $user->id;
    }
}
