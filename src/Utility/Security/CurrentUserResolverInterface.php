<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;

interface CurrentUserResolverInterface
{
    public function resolve(): ?UserEntity;
}
