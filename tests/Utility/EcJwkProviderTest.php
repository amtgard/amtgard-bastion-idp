<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\EcJwkProvider;
use PHPUnit\Framework\TestCase;

class EcJwkProviderTest extends TestCase
{
    public function testJwkIsPublicEcSigningKey(): void
    {
        $provider = new EcJwkProvider(OidcFixtureKeys::publicEcPem());
        $jwk = $provider->jwk();

        $this->assertSame('EC', $jwk['kty']);
        $this->assertSame('P-256', $jwk['crv']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('ES256', $jwk['alg']);
        $this->assertSame($provider->kid(), $jwk['kid']);
        $this->assertSame($this->expectedX(), $jwk['x']);
        $this->assertSame($this->expectedY(), $jwk['y']);
        $this->assertSame(
            ['kty', 'crv', 'use', 'alg', 'kid', 'x', 'y'],
            array_keys($jwk)
        );
        $this->assertArrayNotHasKey('d', $jwk);
        $this->assertDoesNotMatchRegularExpression('/=/', $jwk['x']);
        $this->assertDoesNotMatchRegularExpression('/=/', $jwk['y']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $jwk['x']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $jwk['y']);
    }

    public function testKidIsRfc7638Sha256Thumbprint(): void
    {
        $provider = new EcJwkProvider(OidcFixtureKeys::publicEcPem());

        $this->assertSame($this->expectedThumbprint(), $provider->kid());
        $this->assertDoesNotMatchRegularExpression('/=/', $provider->kid());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $provider->kid());
    }

    public function testInvalidPemThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to parse public key for JWKS.');

        new EcJwkProvider('not-a-key');
    }

    public function testNonEcKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OIDC signing key must be a P-256 EC public key.');

        new EcJwkProvider(OidcFixtureKeys::publicPem());
    }

    public function testNonP256CurveThrows(): void
    {
        $p384 = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp384r1',
        ]);
        $this->assertNotFalse($p384);
        $details = openssl_pkey_get_details($p384);
        $this->assertIsArray($details);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OIDC signing key must be a P-256 EC public key.');

        new EcJwkProvider($details['key']);
    }

    private function expectedThumbprint(): string
    {
        $canonical = json_encode(
            [
                'crv' => 'P-256',
                'kty' => 'EC',
                'x' => $this->expectedX(),
                'y' => $this->expectedY(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $this->base64Url(hash('sha256', $canonical, true));
    }

    private function expectedX(): string
    {
        return $this->base64Url($this->ecDetails()['x']);
    }

    private function expectedY(): string
    {
        return $this->base64Url($this->ecDetails()['y']);
    }

    /**
     * @return array{curve_name: string, x: string, y: string}
     */
    private function ecDetails(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public(OidcFixtureKeys::publicEcPem()));
        $this->assertIsArray($details);

        return $details['ec'];
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
