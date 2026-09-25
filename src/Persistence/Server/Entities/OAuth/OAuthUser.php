<?php

declare(strict_types=1);


namespace Amtgard\IdP\Persistence\Server\Entities\OAuth;

use Amtgard\IdP\Models\Oidc\OidcClaimFactory;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\SerializationTrait;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;
use OpenIDConnectServer\Entities\ClaimSetInterface;

class OAuthUser implements UserEntityInterface, ClaimSetInterface
{
    use EntityTrait;
    use Builder, Data;
    use SerializationTrait;

    private UserEntity $userEntity;

    public function getClaims(): array
    {
        return OidcClaimFactory::fromUser($this->userEntity);
    }
}