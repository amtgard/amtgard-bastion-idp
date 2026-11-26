<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use PHPUnit\Framework\TestCase;

/**
 * Container Signer binding (ES256 design §5.1a): the configured private key
 * selects the lcobucci signer at container-build time; anything else throws.
 */
class SignerContainerBindingTest extends TestCase
{
    public function testEcFixtureKeyResolvesEcdsaSigner(): void
    {
        $this->assertInstanceOf(EcdsaSha256::class, $this->resolveSigner(OidcFixtureKeys::privateEcPem()));
    }

    public function testRsaFixtureKeyResolvesRsaSigner(): void
    {
        $this->assertInstanceOf(RsaSha256::class, $this->resolveSigner(OidcFixtureKeys::privatePem()));
    }

    public function testEd25519KeyThrows(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OAuth signing key must be RSA or prime256v1 EC.');

        $this->resolveSigner($pem);
    }

    public function testGarbagePemThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to parse OAuth private key for signer binding.');

        // Garbage inline PEM: reaches openssl_pkey_get_private (a bare
        // string would be treated as a filesystem path by readFromEnv).
        $this->resolveSigner("-----BEGIN PRIVATE KEY-----\nZ2FyYmFnZQ==\n-----END PRIVATE KEY-----\n");
    }

    public function testP384KeyThrows(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp384r1',
        ]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OAuth signing key must be RSA or prime256v1 EC.');

        $this->resolveSigner($pem);
    }

    private function resolveSigner(string $privateKeyPem): Signer
    {
        $previousEnv = $_ENV['OAUTH_PRIVATE_KEY'] ?? null;
        $previousPutenv = getenv('OAUTH_PRIVATE_KEY');
        $_ENV['OAUTH_PRIVATE_KEY'] = $privateKeyPem;
        putenv('OAUTH_PRIVATE_KEY=' . $privateKeyPem);

        try {
            $definitions = require __DIR__ . '/../../config/container/oauth-server.php';
            $signer = $definitions[Signer::class]();

            $this->assertInstanceOf(Signer::class, $signer);

            return $signer;
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['OAUTH_PRIVATE_KEY']);
            } else {
                $_ENV['OAUTH_PRIVATE_KEY'] = $previousEnv;
            }

            if ($previousPutenv === false) {
                putenv('OAUTH_PRIVATE_KEY');
            } else {
                putenv('OAUTH_PRIVATE_KEY=' . $previousPutenv);
            }
        }
    }
}
