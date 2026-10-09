<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Optional\Optional;

final class CurrentUserResolver implements CurrentUserResolverInterface
{
    use Builder;
    use Getter;

    protected UserRepository $userRepository;

    protected ?IdpLogging $idpLogging = null;

    public function resolve(): ?UserEntity
    {
        if (!isset($_SESSION) || !array_key_exists('user_id', $_SESSION)) {
            $this->idpLogging?->security()->debug('current user resolve skipped', [
                'reason' => 'no_session_user',
            ]);

            return null;
        }

        $sessionUserId = (string) $_SESSION['user_id'];

        /** @var OAuthUser|null $user */
        $user = $this->userRepository->getUserEntityById($sessionUserId);

        return Optional::ofNullable($user)
            ->map(fn (OAuthUser $u) => $u->getUserEntity())
            ->orElseGet(function () use ($sessionUserId): null {
                $this->idpLogging?->security()->warning('current user resolve failed', [
                    'session_user_id' => $sessionUserId,
                    'reason' => 'user_missing',
                ]);

                return null;
            });
    }
}
