<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Optional\Optional;

final class ResourcesUserinfoService
{
    use Builder;
    use Getter;

    protected UserOrkProfileRepository $orkProfileRepository;

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(UserEntity $user): array
    {
        $userData = [
            'id' => $user->getUserId(),
            'email' => $user->getEmail(),
        ];

        Optional::ofNullable($this->orkProfileRepository->findByUserId($user->getId()))
            ->ifPresent(function (UserOrkProfileEntity $orkProfile) use (&$userData): void {
                $userData['ork_profile'] = $orkProfile->toUserinfoProfileArray();
            });

        return $userData;
    }
}
