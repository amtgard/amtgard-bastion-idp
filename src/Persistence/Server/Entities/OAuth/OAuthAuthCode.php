<?php

namespace Amtgard\IdP\Persistence\Server\Entities\OAuth;

use Amtgard\IdP\Persistence\Server\Entities\Repository\AuthCode;
use Amtgard\IdP\Persistence\Server\Entities\SerializationTrait;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

class OAuthAuthCode implements AuthCodeEntityInterface
{
    use TokenEntityTrait, EntityTrait, AuthCodeTrait;
    use Builder, Data;
    use SerializationTrait;
    protected AuthCode $authCodeEntity;
}