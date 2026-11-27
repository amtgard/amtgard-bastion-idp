<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

/**
 * One signing key's JWKS entry (ES256 design §5.5). Each implementation
 * parses its own PEM and computes its own RFC 7638 thumbprint kid.
 *
 * Wired in M2: the container JWKS factory binds the provider list
 * (ES256 design §5.5). Psalm analyses src/ only, so construction from
 * config/ and use from tests does not count as use.
 */
interface JwkProvider
{
    /**
     * @return array<string, string> the full public JWK entry, including kid
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function jwk(): array;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function kid(): string;
}
