<?php

declare(strict_types=1);


namespace Amtgard\IdP\Utility;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\Security\CurrentUserResolver;
use Amtgard\IdP\Utility\Security\CurrentUserResolverInterface;

final class Utility
{
    public static function userIsAuthenticated() {
        return isset($_SESSION) && array_key_exists('user_id', $_SESSION);
    }

    public static function dateFrom(\DateInterval $dateInterval): \DateTimeInterface {
        return (new \DateTimeImmutable())->add($dateInterval);
    }

    /**
     * @deprecated Prefer injecting {@see CurrentUserResolverInterface} in new code.
     */
    public static function getAuthenticatedUser(): ?UserEntity {
        static $resolver = null;
        if ($resolver === null) {
            $resolver = CurrentUserResolver::builder()
                ->userRepository(EntityManager::getManager()->getRepository(UserRepository::class))
                ->build();
        }

        return $resolver->resolve();
    }
}
