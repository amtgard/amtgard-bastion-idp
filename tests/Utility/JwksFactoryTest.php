<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\EcJwkProvider;
use Amtgard\IdP\Utility\JwksFactory;
use Amtgard\IdP\Utility\RsaJwkProvider;
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

    public function testFromPublicKeyPemDispatchesEcKey(): void
    {
        $factory = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicEcPem());

        $this->assertSame(
            (new EcJwkProvider(OidcFixtureKeys::publicEcPem()))->kid(),
            $factory->kid()
        );
        $this->assertSame('EC', $factory->jwk()['kty']);
        $this->assertSame('ES256', $factory->jwk()['alg']);
        $this->assertCount(1, $factory->document()['keys']);
    }

    public function testFromPublicKeyPemRejectsNonP256Curve(): void
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

        JwksFactory::fromPublicKeyPem($details['key']);
    }

    public function testFromPublicKeyPemRejectsUnsupportedKeyType(): void
    {
        $ed25519 = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_ED25519,
        ]);
        $this->assertNotFalse($ed25519);
        $details = openssl_pkey_get_details($ed25519);
        $this->assertIsArray($details);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OIDC signing key must be an RSA or P-256 EC public key.');

        JwksFactory::fromPublicKeyPem($details['key']);
    }

    public function testEcKidIsRfc7638Sha256Thumbprint(): void
    {
        $factory = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicEcPem());
        $kid = $factory->kid();

        $this->assertSame($this->expectedEcThumbprint(), $kid);
        $this->assertDoesNotMatchRegularExpression('/=/', $kid);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $kid);
    }

    public function testEcJwkRoundTripReDerivesSameKid(): void
    {
        $factory = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicEcPem());
        $jwk = $factory->jwk();

        $this->assertSame('EC', $jwk['kty']);
        $this->assertSame('P-256', $jwk['crv']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('ES256', $jwk['alg']);
        $this->assertSame($this->expectedEcX(), $jwk['x']);
        $this->assertSame($this->expectedEcY(), $jwk['y']);
        $this->assertSame($factory->kid(), $jwk['kid']);
        $this->assertArrayNotHasKey('d', $jwk);

        // Re-derive the RFC 7638 thumbprint from the emitted JWK members.
        $canonical = json_encode(
            [
                'crv' => $jwk['crv'],
                'kty' => $jwk['kty'],
                'x' => $jwk['x'],
                'y' => $jwk['y'],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $this->assertIsString($canonical);
        $this->assertSame($this->base64Url(hash('sha256', $canonical, true)), $jwk['kid']);
        $this->assertSame($this->expectedEcThumbprint(), $jwk['kid']);
    }

    public function testAggregatorEmitsTwoKeysEcCurrentFirstDuringOverlap(): void
    {
        $ec = new EcJwkProvider(OidcFixtureKeys::publicEcPem());
        $rsa = new RsaJwkProvider(OidcFixtureKeys::publicPem());
        $factory = JwksFactory::fromProviders($ec, $rsa);

        // kid()/jwk() describe the first (current) provider.
        $this->assertSame($ec->kid(), $factory->kid());
        $this->assertSame($ec->jwk(), $factory->jwk());

        $document = $factory->document();
        $this->assertSame(['keys'], array_keys($document));
        $this->assertCount(2, $document['keys']);
        $this->assertSame($ec->jwk(), $document['keys'][0]);
        $this->assertSame($rsa->jwk(), $document['keys'][1]);
        $this->assertSame('EC', $document['keys'][0]['kty']);
        $this->assertSame('ES256', $document['keys'][0]['alg']);
        $this->assertSame('RSA', $document['keys'][1]['kty']);
        $this->assertSame('RS256', $document['keys'][1]['alg']);
        $this->assertNotSame($document['keys'][0]['kid'], $document['keys'][1]['kid']);
    }

    public function testAggregatorEmitsBoundProvidersCurrentFirst(): void
    {
        $rsa = new RsaJwkProvider(OidcFixtureKeys::publicPem());
        $ec = new EcJwkProvider(OidcFixtureKeys::publicEcPem());
        $factory = JwksFactory::fromProviders($rsa, $ec);

        // kid()/jwk() describe the first (current) provider.
        $this->assertSame($rsa->kid(), $factory->kid());
        $this->assertSame($rsa->jwk(), $factory->jwk());

        $document = $factory->document();
        $this->assertSame(['keys'], array_keys($document));
        $this->assertCount(2, $document['keys']);
        $this->assertSame($rsa->jwk(), $document['keys'][0]);
        $this->assertSame($ec->jwk(), $document['keys'][1]);
        $this->assertSame('RSA', $document['keys'][0]['kty']);
        $this->assertSame('EC', $document['keys'][1]['kty']);
    }

    public function testKidWithNoBoundProvidersThrows(): void
    {
        $factory = new JwksFactory([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWKS has no bound JWK providers.');

        $factory->kid();
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

    private function expectedEcThumbprint(): string
    {
        $canonical = json_encode(
            [
                'crv' => 'P-256',
                'kty' => 'EC',
                'x' => $this->expectedEcX(),
                'y' => $this->expectedEcY(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $this->base64Url(hash('sha256', $canonical, true));
    }

    private function expectedEcX(): string
    {
        return $this->base64Url($this->ecDetails()['x']);
    }

    private function expectedEcY(): string
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
