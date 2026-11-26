<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

/**
 * JWKS document aggregator (ES256 design §5.5). Constructed with the bound
 * JwkProviders, current first; document() emits one entry per provider.
 * kid()/jwk() describe the first (current) provider, preserving the
 * pre-migration single-key behaviour for the existing container path.
 *
 * Psalm analyses src/ only, so construction from config/ and use from
 * tests does not count as use for the providers list (the constructor,
 * fromProviders(), and firstProvider() carry their own suppressions).
 *
 * @psalm-suppress UnusedProperty
 */
final class JwksFactory
{
    /**
     * @param list<JwkProvider> $providers bound providers, current first
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(private readonly array $providers)
    {
    }

    public static function fromPublicKeyPem(string $pem): self
    {
        return new self([new RsaJwkProvider($pem)]);
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public static function fromProviders(JwkProvider ...$providers): self
    {
        return new self($providers);
    }

    public function kid(): string
    {
        return $this->firstProvider()->kid();
    }

    /**
     * @return array<string, string>
     */
    public function jwk(): array
    {
        return $this->firstProvider()->jwk();
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function document(): array
    {
        return [
            'keys' => array_map(
                static fn (JwkProvider $provider): array => $provider->jwk(),
                $this->providers
            ),
        ];
    }

    /** @psalm-suppress UnusedMethod */
    private function firstProvider(): JwkProvider
    {
        $first = $this->providers[0] ?? null;
        if ($first === null) {
            throw new \RuntimeException('JWKS has no bound JWK providers.');
        }

        return $first;
    }
}
