<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Optional\Optional;

final class ResourcesUserinfoService
{
    use Builder;
    use Getter;

    protected UserOrkProfileRepository $orkProfileRepository;

    protected ?IdpLogging $idpLogging = null;

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(UserEntity $user): array
    {
        $userData = [
            'id' => $user->getUserId(),
            'email' => $user->getEmail(),
        ];

        $orkProfile = $this->orkProfileRepository->findByUserId($user->getId());
        Optional::ofNullable($orkProfile)
            ->ifPresent(function (UserOrkProfileEntity $profile) use (&$userData): void {
                $userData['ork_profile'] = $profile->toUserinfoProfileArray();
            });

        $this->idpLogging?->app()->debug('resources userinfo payload built', [
            'user_uuid' => $user->getUserId(),
            'has_ork_profile' => $orkProfile !== null,
        ]);

        return $userData;
    }
}
