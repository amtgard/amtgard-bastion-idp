<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\JwksFactory;
use Amtgard\IdP\Utility\RsaJwkProvider;
use PHPUnit\Framework\TestCase;

class RsaJwkProviderTest extends TestCase
{
    public function testJwkIsPublicRsaSigningKey(): void
    {
        $provider = new RsaJwkProvider(OidcFixtureKeys::publicPem());
        $jwk = $provider->jwk();

        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame($provider->kid(), $jwk['kid']);
        $this->assertSame($this->expectedModulus(), $jwk['n']);
        $this->assertSame($this->expectedExponent(), $jwk['e']);
        $this->assertSame(
            ['kty', 'use', 'alg', 'kid', 'n', 'e'],
            array_keys($jwk)
        );
        $this->assertArrayNotHasKey('d', $jwk);
        $this->assertArrayNotHasKey('p', $jwk);
        $this->assertArrayNotHasKey('q', $jwk);
        $this->assertArrayNotHasKey('dp', $jwk);
        $this->assertArrayNotHasKey('dq', $jwk);
        $this->assertArrayNotHasKey('qi', $jwk);
    }

    public function testKidIsRfc7638Sha256Thumbprint(): void
    {
        $provider = new RsaJwkProvider(OidcFixtureKeys::publicPem());

        $this->assertSame($this->expectedThumbprint(), $provider->kid());
        $this->assertDoesNotMatchRegularExpression('/=/', $provider->kid());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $provider->kid());
    }

    public function testKidIsByteIdenticalToLegacyJwksFactoryKid(): void
    {
        $provider = new RsaJwkProvider(OidcFixtureKeys::publicPem());

        $this->assertSame(
            JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem())->kid(),
            $provider->kid()
        );
    }

    public function testInvalidPemThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to parse public key for JWKS.');

        new RsaJwkProvider('not-a-key');
    }

    public function testNonRsaKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OIDC signing key must be an RSA public key.');

        new RsaJwkProvider(OidcFixtureKeys::publicEcPem());
    }

    private function expectedThumbprint(): string
    {
        $canonical = json_encode(
            [
                'e' => $this->expectedExponent(),
                'kty' => 'RSA',
                'n' => $this->expectedModulus(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $this->base64Url(hash('sha256', $canonical, true));
    }

    private function expectedModulus(): string
    {
        return $this->base64Url($this->rsaDetails()['n']);
    }

    private function expectedExponent(): string
    {
        return $this->base64Url($this->rsaDetails()['e']);
    }

    /**
     * @return array{n: string, e: string}
     */
    private function rsaDetails(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public(OidcFixtureKeys::publicPem()));
        $this->assertIsArray($details);

        return $details['rsa'];
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
