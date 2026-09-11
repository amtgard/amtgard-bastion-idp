<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Optional\Optional;

final class CurrentUserResolver implements CurrentUserResolverInterface
{
    use Builder;
    use Getter;

    protected UserRepository $userRepository;

    public function resolve(): ?UserEntity
    {
        if (!isset($_SESSION) || !array_key_exists('user_id', $_SESSION)) {
            return null;
        }

        /** @var OAuthUser|null $user */
        $user = $this->userRepository->getUserEntityById((string) $_SESSION['user_id']);

        return Optional::ofNullable($user)
            ->map(fn (OAuthUser $u) => $u->getUserEntity())
            ->orElse(null);
    }
}
