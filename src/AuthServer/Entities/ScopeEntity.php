<?php
declare(strict_types=1);

namespace Amtgard\IdP\Auth\Entities;

use JsonSerializable;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ScopeEntity implements ScopeEntityInterface
{
    use EntityTrait;

    /**
     * Serialize the scope entity.
     *
     * @return string
     */
    public function jsonSerialize()
    {
        return $this->getIdentifier();
    }
}