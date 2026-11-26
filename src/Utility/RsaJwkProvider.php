<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

/**
 * RSA JWK provider (ES256 design §5.5). Emits
 * {kty: 'RSA', n, e, use: 'sig', alg: 'RS256', kid}. The kid is
 * byte-identical to the pre-migration JwksFactory kid for the same key.
 *
 * Wired in M2 via JwksFactory::providerFromPublicKeyPem() and the
 * container JWKS factory. Psalm analyses src/ only, so construction from
 * config/ and use from tests does not count as use.
 *
 * @psalm-suppress UnusedClass
 */
final class RsaJwkProvider implements JwkProvider
{
    /** @var array{e: string, kty: string, n: string} */
    private readonly array $requiredMembers;

    public function __construct(string $publicKeyPem)
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new \RuntimeException('Unable to parse public key for JWKS.');
        }

        $details = openssl_pkey_get_details($key);
        $rsa = \is_array($details) ? ($details['rsa'] ?? null) : null;
        if (!\is_array($rsa) || !isset($rsa['n'], $rsa['e'])) {
            throw new \RuntimeException('OIDC signing key must be an RSA public key.');
        }

        $this->requiredMembers = [
            'e' => self::base64UrlEncode((string) $rsa['e']),
            'kty' => 'RSA',
            'n' => self::base64UrlEncode((string) $rsa['n']),
        ];
    }

    #[\Override]
    public function kid(): string
    {
        // RFC 7638: lexicographic members e, kty, n with no insignificant whitespace.
        $canonical = '{"e":"' . $this->requiredMembers['e']
            . '","kty":"' . $this->requiredMembers['kty']
            . '","n":"' . $this->requiredMembers['n'] . '"}';

        return self::base64UrlEncode(hash('sha256', $canonical, true));
    }

    /**
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    #[\Override]
    public function jwk(): array
    {
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->kid(),
            'n' => $this->requiredMembers['n'],
            'e' => $this->requiredMembers['e'],
        ];
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
