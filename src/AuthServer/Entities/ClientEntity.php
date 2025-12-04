<?php
declare(strict_types=1);

namespace Amtgard\IdP\Auth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use EntityTrait, ClientTrait;

    private bool $confidential = false;

    /**
     * Set the name of the client.
     *
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Set the client's redirect URI.
     *
     * @param string $uri
     */
    public function setRedirectUri(string $uri): void
    {
        $this->redirectUri = $uri;
    }

    /**
     * Returns true if the client is confidential.
     *
     * @return bool
     */
    public function isConfidential()
    {
        return $this->confidential;
    }

    /**
     * Set whether the client is confidential.
     *
     * @param bool $confidential
     */
    public function setConfidential(bool $confidential): void
    {
        $this->confidential = $confidential;
    }
}