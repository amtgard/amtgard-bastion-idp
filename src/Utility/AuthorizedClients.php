<?php

namespace Amtgard\IdP\Utility;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;

class AuthorizedClients
{
    use Builder, Getter;
    protected array $clientIds;
}