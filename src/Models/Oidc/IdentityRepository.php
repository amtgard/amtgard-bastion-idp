<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models\Oidc;

use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use League\OAuth2\Server\Entities\UserEntityInterface;
use OpenIDConnectServer\Repositories\IdentityProviderInterface;

final class IdentityRepository implements IdentityProviderInterface
{
    public function __construct(private readonly UserRepository $userRepository)
    {
    }

    public function getUserEntityByIdentifier($identifier): ?UserEntityInterface
    {
        return $this->userRepository->getUserEntityById((string) $identifier);
    }
}
