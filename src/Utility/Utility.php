<?php

namespace Amtgard\IdP\Utility;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Optional\Optional;

class Utility
{
    public static function userIsAuthenticated() {
        return array_key_exists('user_id', $_SESSION);
    }

    public static function dateFrom(\DateInterval $dateInterval): \DateTimeInterface {
        return (new \DateTimeImmutable())->add($dateInterval);
    }

    public static function getAuthenticatedUser(): ?UserEntity {
        if (!self::userIsAuthenticated()) {
            return null;
        }

        $userRepo = EntityManager::getManager()->getRepository(UserRepository::class);
        /** @var OAuthUser $user */
        $user = $userRepo->getUserEntityById($_SESSION['user_id']);
        return Optional::ofNullable($user)
            ->map(fn($u) => $u->getUserEntity())
            ->orElse(null);
    }
}