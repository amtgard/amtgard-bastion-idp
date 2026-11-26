<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\IdP\Models\Oidc\Es256BearerTokenValidator;
use Amtgard\IdP\Models\Oidc\Rs256BearerTokenValidator;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthAccessToken;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthClient;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use DateTimeZone;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Access-token minting with the injected signer (ES256 design §5.1, §8 M3):
 * ES256 signer mints alg ES256, RS256 signer mints alg RS256, a missing
 * signer throws (D7), and the entity still round-trips through
 * SerializationTrait with the signer property set.
 */
class OAuthAccessTokenTest extends TestCase
{
    public function testMintWithEs256SignerProducesVerifiableEs256Token(): void
    {
        $token = $this->mint(new EcdsaSha256(), OidcFixtureKeys::privateEcPem());

        $this->assertSame('ES256', $this->parse($token, new EcdsaSha256(), OidcFixtureKeys::publicEcPem()));
        $validated = (new Es256BearerTokenValidator(
            $this->unrevokedRepository(),
            OidcFixtureKeys::publicEcPem()
        ))->validateAuthorization($this->bearerRequest($token));

        $this->assertSame('token-id', $validated->getAttribute('oauth_access_token_id'));
        $this->assertSame('client-1', $validated->getAttribute('oauth_client_id'));
        $this->assertSame('user-1', $validated->getAttribute('oauth_user_id'));
        $this->assertSame([], $validated->getAttribute('oauth_scopes'));
    }

    public function testMintWithRs256SignerProducesVerifiableRs256Token(): void
    {
        $token = $this->mint(new RsaSha256(), OidcFixtureKeys::privatePem());

        $this->assertSame('RS256', $this->parse($token, new RsaSha256(), OidcFixtureKeys::publicPem()));
        $validated = (new Rs256BearerTokenValidator(
            $this->unrevokedRepository(),
            OidcFixtureKeys::publicPem()
        ))->validateAuthorization($this->bearerRequest($token));

        $this->assertSame('token-id', $validated->getAttribute('oauth_access_token_id'));
        $this->assertSame('client-1', $validated->getAttribute('oauth_client_id'));
        $this->assertSame('user-1', $validated->getAttribute('oauth_user_id'));
    }

    public function testMintWithPassphraseProtectedKeyUsesPassphrase(): void
    {
        $token = OAuthAccessToken::builder()
            ->client($this->client())
            ->signer(new EcdsaSha256())
            ->identifier('token-id')
            ->userIdentifier('user-1')
            ->expiryDateTime(new \DateTimeImmutable('+1 hour'))
            ->scopes([])
            ->build();
        $token->setPrivateKey(new CryptKey(OidcFixtureKeys::privateEcEncryptedPem(), 'test-passphrase', false));

        $this->assertSame('ES256', $this->parse((string) $token, new EcdsaSha256(), OidcFixtureKeys::publicEcPem()));
    }

    public function testMintWithoutSignerThrows(): void
    {
        $token = OAuthAccessToken::builder()
            ->client($this->client())
            ->identifier('token-id')
            ->userIdentifier('user-1')
            ->expiryDateTime(new \DateTimeImmutable('+1 hour'))
            ->scopes([])
            ->build();
        $token->setPrivateKey(new CryptKey(OidcFixtureKeys::privateEcPem(), null, false));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signer was not injected');

        (string) $token;
    }

    public function testSerializationRoundTripsWithSignerSet(): void
    {
        $token = OAuthAccessToken::builder()
            ->client($this->client())
            ->signer(new EcdsaSha256())
            ->identifier('token-id')
            ->userIdentifier('user-1')
            ->expiryDateTime(new \DateTimeImmutable('+1 hour'))
            ->scopes([])
            ->build();
        $token->setPrivateKey(new CryptKey(OidcFixtureKeys::privateEcPem(), null, false));

        // Serialize before minting: initJwtConfiguration() populates
        // jwtConfiguration, which holds a Closure (lcobucci Configuration
        // builder factory) and cannot serialize — pre-existing, identical
        // under the trait's own RS256 config. The signer property itself
        // must round-trip cleanly.
        $restored = unserialize(serialize($token));

        $this->assertInstanceOf(OAuthAccessToken::class, $restored);
        $this->assertSame('token-id', $restored->getIdentifier());
        $this->assertSame('user-1', $restored->getUserIdentifier());
        $this->assertSame('client-1', $restored->getClient()->getIdentifier());

        // The signer survived the round-trip: the restored entity mints again.
        $this->assertSame('ES256', $this->parse((string) $restored, new EcdsaSha256(), OidcFixtureKeys::publicEcPem()));
    }

    private function mint(Signer $signer, string $privatePem): string
    {
        $token = OAuthAccessToken::builder()
            ->client($this->client())
            ->signer($signer)
            ->identifier('token-id')
            ->userIdentifier('user-1')
            ->expiryDateTime(new \DateTimeImmutable('+1 hour'))
            ->scopes([])
            ->build();
        $token->setPrivateKey(new CryptKey($privatePem, null, false));

        return (string) $token;
    }

    private function client(): OAuthClient
    {
        return OAuthClient::builder()->identifier('client-1')->build();
    }

    /**
     * Parse the token, assert its signature verifies under the given key,
     * and return its alg header.
     */
    private function parse(string $jwt, Signer $signer, string $publicPem): string
    {
        $configuration = Configuration::forAsymmetricSigner(
            $signer,
            InMemory::plainText($publicPem, ''),
            InMemory::plainText($publicPem, '')
        );
        $parsed = $configuration->parser()->parse($jwt);
        $configuration->validator()->assert(
            $parsed,
            new SignedWith($signer, InMemory::plainText($publicPem, '')),
            new LooseValidAt(new SystemClock(new DateTimeZone(date_default_timezone_get())))
        );

        return $parsed->headers()->get('alg');
    }

    private function unrevokedRepository(): AccessTokenRepositoryInterface
    {
        $repository = $this->createStub(AccessTokenRepositoryInterface::class);
        $repository->method('isAccessTokenRevoked')->willReturn(false);

        return $repository;
    }

    private function bearerRequest(string $jwt): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/resources/userinfo')
            ->withHeader('Authorization', 'Bearer ' . $jwt);
    }
}
