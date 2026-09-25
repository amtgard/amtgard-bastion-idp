<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\JwksFactory;
use PHPUnit\Framework\TestCase;

class JwksFactoryTest extends TestCase
{
    public function testKidIsRfc7638Sha256Thumbprint(): void
    {
        $factory = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem());
        $kid = $factory->kid();

        $this->assertSame($this->expectedThumbprint(), $kid);
        $this->assertDoesNotMatchRegularExpression('/=/', $kid);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $kid);
    }

    public function testJwkIsPublicRsaSigningKey(): void
    {
        $factory = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem());
        $jwk = $factory->jwk();

        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame($factory->kid(), $jwk['kid']);
        $this->assertSame($this->expectedModulus(), $jwk['n']);
        $this->assertSame($this->expectedExponent(), $jwk['e']);
        $this->assertArrayNotHasKey('d', $jwk);
        $this->assertArrayNotHasKey('p', $jwk);
        $this->assertArrayNotHasKey('q', $jwk);
        $this->assertArrayNotHasKey('dp', $jwk);
        $this->assertArrayNotHasKey('dq', $jwk);
        $this->assertArrayNotHasKey('qi', $jwk);
        $this->assertDoesNotMatchRegularExpression('/=/', $jwk['n']);
        $this->assertDoesNotMatchRegularExpression('/=/', $jwk['e']);
    }

    public function testDocumentIsASinglePublicRsaJwk(): void
    {
        $factory = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem());
        $document = $factory->document();

        $this->assertSame(['keys'], array_keys($document));
        $this->assertCount(1, $document['keys']);
        $this->assertSame($factory->jwk(), $document['keys'][0]);
        $this->assertSame(
            ['kty', 'use', 'alg', 'kid', 'n', 'e'],
            array_keys($document['keys'][0])
        );
        $this->assertArrayNotHasKey('d', $document['keys'][0]);
        $this->assertArrayNotHasKey('p', $document['keys'][0]);
        $this->assertArrayNotHasKey('q', $document['keys'][0]);
        $this->assertArrayNotHasKey('dp', $document['keys'][0]);
        $this->assertArrayNotHasKey('dq', $document['keys'][0]);
        $this->assertArrayNotHasKey('qi', $document['keys'][0]);
    }

    public function testSameKeyProducesStableKid(): void
    {
        $pem = OidcFixtureKeys::publicPem();

        $this->assertSame(
            JwksFactory::fromPublicKeyPem($pem)->kid(),
            JwksFactory::fromPublicKeyPem($pem)->kid()
        );
    }

    public function testDifferentKeysProduceDifferentKids(): void
    {
        $other = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($other);
        $details = openssl_pkey_get_details($other);
        $this->assertIsArray($details);

        $this->assertNotSame(
            JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem())->kid(),
            JwksFactory::fromPublicKeyPem($details['key'])->kid()
        );
    }

    public function testInvalidPemThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to parse public key for JWKS.');

        JwksFactory::fromPublicKeyPem('not-a-key');
    }

    public function testNonRsaKeyThrows(): void
    {
        $ec = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($ec);
        $details = openssl_pkey_get_details($ec);
        $this->assertIsArray($details);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OIDC signing key must be an RSA public key.');

        JwksFactory::fromPublicKeyPem($details['key']);
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
