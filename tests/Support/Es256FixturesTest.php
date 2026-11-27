<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

final class Es256FixturesTest extends TestCase
{
    public function testEcFixtureRoundTrip(): void
    {
        $privateKey = openssl_pkey_get_private(OidcFixtureKeys::privateEcPem());
        $this->assertNotFalse($privateKey);
        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);
        $this->assertSame(OPENSSL_KEYTYPE_EC, $details['type']);
        $this->assertSame('prime256v1', $details['ec']['curve_name']);

        // Re-deriving the public key from the private fixture matches
        // the committed public fixture.
        $this->assertSame(
            trim(OidcFixtureKeys::publicEcPem()),
            trim($details['key']),
        );

        $publicKey = openssl_pkey_get_public(OidcFixtureKeys::publicEcPem());
        $this->assertNotFalse($publicKey);
        $publicDetails = openssl_pkey_get_details($publicKey);
        $this->assertIsArray($publicDetails);
        $this->assertSame(OPENSSL_KEYTYPE_EC, $publicDetails['type']);
        $this->assertSame('prime256v1', $publicDetails['ec']['curve_name']);
    }

    public function testFactoryMintsEs256TokenDecodableWithEcPublicKey(): void
    {
        $claims = [
            'iss' => 'http://localhost',
            'aud' => 'test-client',
            'sub' => 'user-1',
            'exp' => time() + 3600,
        ];
        $token = FirebaseJwtTestFactory::encodeEs256($claims);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $header = json_decode(
            (string) base64_decode(strtr($parts[0], '-_', '+/')),
            true,
        );
        $this->assertSame('ES256', $header['alg']);

        $decoded = FirebaseJwt::decode($token, new Key(OidcFixtureKeys::publicEcPem(), 'ES256'));
        $this->assertSame('user-1', $decoded->sub);
        $this->assertSame('test-client', $decoded->aud);
        $this->assertSame('http://localhost', $decoded->iss);
    }
}
