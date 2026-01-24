<?php

namespace Amtgard\IdP\Persistence\Server\Entities\OAuth;

use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Entities\SerializationTrait;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class OAuthClient implements ClientEntityInterface
{
    use EntityTrait, ClientTrait;
    use Builder, Data;
    use SerializationTrait;

    private Client $clientEntity;

}