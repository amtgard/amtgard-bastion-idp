<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models\Oidc;

final class OidcNonceContext
{
    private ?string $nonce = null;

    public function set(string $nonce): void
    {
        $this->nonce = $nonce;
    }

    public function get(): ?string
    {
        return $this->nonce;
    }

    public function clear(): void
    {
        $this->nonce = null;
    }
}
