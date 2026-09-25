<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Tests\Support\OidcTokenExchangeHarness;
use Amtgard\IdP\Utility\JwksFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

class OidcTokenEndpointTest extends TestCase
{
    public function testPostOauthTokenWithOpenidReturnsVerifiableIdToken(): void
    {
        $body = OidcTokenExchangeHarness::exchange('openid profile email');

        $this->assertArrayHasKey('access_token', $body);
        $this->assertArrayHasKey('refresh_token', $body);
        $this->assertArrayHasKey('expires_in', $body);
        $this->assertArrayHasKey('token_type', $body);
        $this->assertArrayHasKey('id_token', $body);

        $publicKey = new Key(OidcFixtureKeys::publicPem(), 'RS256');
        $idToken = JWT::decode($body['id_token'], $publicKey);
        $accessToken = JWT::decode($body['access_token'], $publicKey);
        $kid = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem())->kid();

        $this->assertSame('https://idp.amtgard.com', $idToken->iss);
        $this->assertSame(OidcTokenExchangeHarness::CLIENT_ID, $this->audience($idToken->aud));
        $this->assertSame(OidcTokenExchangeHarness::USER_ID, $idToken->sub);
        $this->assertSame((int) $accessToken->exp, (int) $idToken->exp);
        $this->assertSame($kid, $this->header($body['id_token'])['kid']);
        $this->assertObjectNotHasProperty('nonce', $idToken);
    }

    public function testPostOauthTokenWithoutOpenidOmitsIdToken(): void
    {
        $body = OidcTokenExchangeHarness::exchange('profile email');

        $this->assertArrayHasKey('access_token', $body);
        $this->assertArrayHasKey('refresh_token', $body);
        $this->assertArrayHasKey('expires_in', $body);
        $this->assertArrayHasKey('token_type', $body);
        $this->assertArrayNotHasKey('id_token', $body);
        $this->assertSame(
            ['token_type', 'expires_in', 'access_token', 'refresh_token'],
            array_keys($body)
        );
    }

    public function testCodeExchangeIdTokenEchoesNonce(): void
    {
        $body = OidcTokenExchangeHarness::exchange('openid', 'rp-nonce-1');

        $this->assertArrayHasKey('id_token', $body);
        $idToken = JWT::decode($body['id_token'], new Key(OidcFixtureKeys::publicPem(), 'RS256'));
        $this->assertSame('rp-nonce-1', $idToken->nonce);
    }

    public function testRefreshGrantIdTokenOmitsNonce(): void
    {
        $codeBody = OidcTokenExchangeHarness::exchange('openid', 'rp-nonce-1');
        $this->assertArrayHasKey('refresh_token', $codeBody);
        $this->assertSame(
            'rp-nonce-1',
            JWT::decode($codeBody['id_token'], new Key(OidcFixtureKeys::publicPem(), 'RS256'))->nonce
        );

        $refreshBody = OidcTokenExchangeHarness::refresh($codeBody['refresh_token']);
        $this->assertArrayHasKey('id_token', $refreshBody);
        $refreshToken = JWT::decode($refreshBody['id_token'], new Key(OidcFixtureKeys::publicPem(), 'RS256'));
        $this->assertObjectNotHasProperty('nonce', $refreshToken);
    }

    public function testIdTokenIssuerIsAppUrlWhenRequestHostDiffers(): void
    {
        $body = OidcTokenExchangeHarness::exchange('openid');
        $idToken = JWT::decode($body['id_token'], new Key(OidcFixtureKeys::publicPem(), 'RS256'));

        $this->assertSame('https://idp.amtgard.com', $idToken->iss);
        $this->assertNotSame('https://evil.example', $idToken->iss);
    }

    private function audience(mixed $aud): string
    {
        if (is_array($aud)) {
            $this->assertCount(1, $aud);

            return (string) $aud[0];
        }

        return (string) $aud;
    }

    /**
     * @return array<string, mixed>
     */
    private function header(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $this->assertGreaterThanOrEqual(2, count($parts));

        return json_decode(
            base64_decode(strtr($parts[0], '-_', '+/'), true),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}
