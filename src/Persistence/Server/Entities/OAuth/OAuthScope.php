<?php

namespace Amtgard\IdP\Persistence\Server\Entities\OAuth;

use Amtgard\IdP\Persistence\Server\Entities\Repository\Scope;
use Amtgard\IdP\Persistence\Server\Entities\SerializationTrait;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

class OAuthScope implements ScopeEntityInterface
{
    use EntityTrait, ScopeTrait;
    use Builder, Data;
    use SerializationTrait;

    private Scope $scopeEntity;
}