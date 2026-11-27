<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

/**
 * EC JWK provider (ES256 design §5.5). Parses a P-256 EC PEM and emits
 * {kty: 'EC', crv: 'P-256', x, y, use: 'sig', alg: 'ES256', kid}. Any other
 * curve is rejected, so a future curve addition is a deliberate change.
 *
 * Wired in M2 via JwksFactory::providerFromPublicKeyPem() and the
 * container JWKS factory. Psalm analyses src/ only, so construction from
 * config/ and use from tests does not count as use.
 *
 * @psalm-suppress UnusedClass
 */
final class EcJwkProvider implements JwkProvider
{
    /**
     * @var array{crv: string, kty: string, x: string, y: string}
     *
     * @psalm-suppress UnusedProperty Read by kid() and jwk() below; psalm
     * flags this once M6 constructs the providers from analysed src
     * (bearer-token validators) because the reads go through the
     * JwkProvider interface, which its whole-program analysis cannot see.
     */
    private readonly array $requiredMembers;

    public function __construct(string $publicKeyPem)
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new \RuntimeException('Unable to parse public key for JWKS.');
        }

        $details = openssl_pkey_get_details($key);
        $ec = \is_array($details) ? ($details['ec'] ?? null) : null;
        if (
            !\is_array($ec)
            || !isset($ec['curve_name'], $ec['x'], $ec['y'])
            || $ec['curve_name'] !== 'prime256v1'
        ) {
            throw new \RuntimeException('OIDC signing key must be a P-256 EC public key.');
        }

        $this->requiredMembers = [
            'crv' => 'P-256',
            'kty' => 'EC',
            'x' => self::base64UrlEncode((string) $ec['x']),
            'y' => self::base64UrlEncode((string) $ec['y']),
        ];
    }

    #[\Override]
    public function kid(): string
    {
        // RFC 7638: lexicographic members crv, kty, x, y with no insignificant whitespace.
        $canonical = '{"crv":"' . $this->requiredMembers['crv']
            . '","kty":"' . $this->requiredMembers['kty']
            . '","x":"' . $this->requiredMembers['x']
            . '","y":"' . $this->requiredMembers['y'] . '"}';

        return self::base64UrlEncode(hash('sha256', $canonical, true));
    }

    /**
     * @return array{kty: string, crv: string, use: string, alg: string, kid: string, x: string, y: string}
     */
    #[\Override]
    public function jwk(): array
    {
        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'use' => 'sig',
            'alg' => 'ES256',
            'kid' => $this->kid(),
            'x' => $this->requiredMembers['x'],
            'y' => $this->requiredMembers['y'],
        ];
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
