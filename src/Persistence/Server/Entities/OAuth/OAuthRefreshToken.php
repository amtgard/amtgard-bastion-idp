<?php

namespace Amtgard\IdP\Persistence\Server\Entities\OAuth;

use Amtgard\IdP\Persistence\Server\Entities\Repository\RefreshToken;
use Amtgard\IdP\Persistence\Server\Entities\SerializationTrait;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

class OAuthRefreshToken implements RefreshTokenEntityInterface
{
    use EntityTrait, RefreshTokenTrait;
    use Builder, Data;
    use SerializationTrait;

    private RefreshToken $refreshToken;
}