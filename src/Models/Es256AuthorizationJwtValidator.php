<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;

/**
 * ES256 authorization-JWT validator (ES256 design §5.4). Accepts ES256
 * tokens signed with the P-256 public key only.
 *
 * M1 only: no production consumers yet (wired in M5). Psalm analyses
 * src/ only, so construction from config/ and use from tests does not
 * count as use.
 *
 * @psalm-suppress UnusedClass
 */
final class Es256AuthorizationJwtValidator implements AuthorizationJwtValidator
{
    public function __construct(private readonly string $publicKeyPem)
    {
    }

    #[\Override]
    public function validateJwtSignature(string $putativeJwt, ?LoggerInterface $logger = null): ?string
    {
        try {
            FirebaseJwt::decode($putativeJwt, new Key($this->publicKeyPem, 'ES256'));
        } catch (\Throwable $e) {
            $logger?->debug('jwt signature validation failed', [
                'reason' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            return null;
        }

        return $putativeJwt;
    }
}
