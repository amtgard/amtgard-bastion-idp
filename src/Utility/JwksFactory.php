<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

/**
 * PEM → RFC 7638 thumbprint kid (and the matching RSA JWK). One key in v1.
 */
final class JwksFactory
{
    /**
     * @param array{e: string, kty: string, n: string} $requiredMembers
     */
    private function __construct(private readonly array $requiredMembers)
    {
    }

    public static function fromPublicKeyPem(string $pem): self
    {
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \RuntimeException('Unable to parse public key for JWKS.');
        }

        $details = openssl_pkey_get_details($key);
        if (!isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new \RuntimeException('OIDC signing key must be an RSA public key.');
        }

        $members = [
            'e' => self::base64UrlEncode($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => self::base64UrlEncode($details['rsa']['n']),
        ];

        return new self($members);
    }

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

    /**
     * @return array{keys: list<array{kty: string, use: string, alg: string, kid: string, n: string, e: string}>}
     */
    public function document(): array
    {
        return [
            'keys' => [$this->jwk()],
        ];
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
