<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Client;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
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

    public function findUserByPublicId(mixed $idpUserId): Optional
    {
        return $this->normalizedString($idpUserId)
            ->map(fn (string $publicId) => $this->userRepository->findUserByUserId($publicId));
    }

    public function findLoginIdForUser(mixed $loginId, int $userDbId): Optional
    {
        return $this->positiveInteger($loginId)
            ->filter(fn (int $resolvedLoginId) => $this->userLoginRepository->loginBelongsToUser(
                $resolvedLoginId,
                $userDbId
            ));
    }

    private function normalizedString(mixed $value): Optional
    {
        return Optional::ofNullable(is_string($value) ? trim($value) : null)
            ->filter(fn (string $normalized) => $normalized !== '');
    }

    private function positiveInteger(mixed $value): Optional
    {
        return Optional::ofNullable(is_numeric($value) ? (int) $value : null)
            ->filter(fn (int $resolved) => $resolved > 0);
    }
}
