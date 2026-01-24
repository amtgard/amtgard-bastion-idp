<?php

namespace Amtgard\IdP\Persistence\Server\Entities\OAuth;

use Amtgard\IdP\Persistence\Server\Entities\Repository\AccessToken;
use Amtgard\IdP\Persistence\Server\Entities\SerializationTrait;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

class OAuthAccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait, TokenEntityTrait, EntityTrait;
    use Builder, Data;
    use SerializationTrait;

    private AccessToken $accessTokenEntity;
}