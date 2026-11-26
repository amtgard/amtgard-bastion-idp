<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models;

use Psr\Log\LoggerInterface;

/**
 * Tries each bound authorization-JWT validator in turn, ES256 current
 * first, then the retiring RS256 (ES256 design §5.4). Try-in-turn with
 * single Keys, not a key array: firebase requires a kid for array lookup
 * and RS256 tokens minted before this change carry no kid.
 *
 * M1 only: no production consumers yet (wired in M5). Psalm analyses
 * src/ only, so construction from config/ and use from tests does not
 * count as use.
 *
 * @psalm-suppress UnusedClass
 */
final class CompositeAuthorizationJwtValidator implements AuthorizationJwtValidator
{
    /** @var list<AuthorizationJwtValidator> */
    private readonly array $validators;

    public function __construct(AuthorizationJwtValidator ...$validators)
    {
        $this->validators = $validators;
    }

    #[\Override]
    public function validateJwtSignature(string $putativeJwt, ?LoggerInterface $logger = null): ?string
    {
        foreach ($this->validators as $validator) {
            $validated = $validator->validateJwtSignature($putativeJwt, $logger);
            if ($validated !== null) {
                return $validated;
            }
        }

        return null;
    }
}
