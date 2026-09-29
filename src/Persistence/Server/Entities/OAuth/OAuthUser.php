<?php

declare(strict_types=1);


namespace Amtgard\IdP\Persistence\Server\Entities\OAuth;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\SerializationTrait;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;
use Optional\Optional;
use ReflectionProperty;

class OAuthUser implements UserEntityInterface
{
    use EntityTrait;
    use Builder, Data;
    use SerializationTrait;

    private UserEntity $userEntity;

    public function attachedUserEntity(): ?UserEntity
    {
        return Optional::of($this)
            ->filter(
                fn (self $user): bool => (new ReflectionProperty(self::class, 'userEntity'))->isInitialized($user)
            )
            ->map(fn (self $user): UserEntity => $user->getUserEntity())
            ->orElse(null);
    }
}