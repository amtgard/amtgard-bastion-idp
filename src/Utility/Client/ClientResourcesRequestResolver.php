<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Client;

use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Optional\Optional;

/**
 * Resolves client IAM API inputs (idp_user_id, login_id) against persistence.
 * Keeps controllers free of branching lookup logic.
 */
final class ClientResourcesRequestResolver
{
    public function __construct(
        private UserRepository $userRepository,
        private UserLoginRepository $userLoginRepository,
    ) {}

    public function findUserByPublicId(string $idpUserId): Optional
    {
        return Optional::ofNullable($this->userRepository->findUserByUserId($idpUserId));
    }

    public function findLoginIdForUser(int $loginId, int $userDbId): Optional
    {
        return Optional::of($loginId)
            ->filter(fn (int $resolvedLoginId) => $this->userLoginRepository->loginBelongsToUser(
                $resolvedLoginId,
                $userDbId
            ));
    }
}
