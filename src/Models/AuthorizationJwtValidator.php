<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models;

use Psr\Log\LoggerInterface;

/**
 * Validates a fat or compact authorization JWT (ES256 design §5.4). One
 * implementation per algorithm; the firebase Key object binds the
 * algorithm, so the alg-confusion guard is enforced by the library.
 *
 * M1 only: no production consumers yet (wired in M5). Psalm analyses
 * src/ only, so use from tests does not count as use.
 */
interface AuthorizationJwtValidator
{
    /**
     * Returns the JWT unchanged when its signature validates, null otherwise.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function validateJwtSignature(string $putativeJwt, ?LoggerInterface $logger = null): ?string;
}
