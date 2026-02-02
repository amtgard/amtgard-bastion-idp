<?php

namespace Amtgard\IdP\Utility;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;

class CachedValidatedUserEntity
{
    use Builder, Getter;
    private string $userId;
    private string $email;
}