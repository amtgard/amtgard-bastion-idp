<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Server\OAuth\DiscoveryController;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Tests\Support\OidcTokenExchangeHarness;
use Amtgard\IdP\Utility\JwksFactory;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class OidcDiscoveryJwksVerifyTest extends TestCase
{
    public function testIdTokenKidMatchesJwksAndVerifiesWithJwkModulus(): void
    {
        $body = OidcTokenExchangeHarness::exchange('openid');
        $this->assertArrayHasKey('id_token', $body);

        $factory = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem());
        $jwksResponse = (new DiscoveryController($factory, 'https://idp.amtgard.com'))->jwks(
            (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/jwks.json'),
            (new ResponseFactory())->createResponse()
        );
        $jwks = json_decode((string) $jwksResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($jwks);
        $this->assertArrayHasKey('keys', $jwks);
        $this->assertCount(1, $jwks['keys']);

        $header = $this->header($body['id_token']);
        $this->assertSame($jwks['keys'][0]['kid'], $header['kid']);
        $this->assertSame($factory->kid(), $header['kid']);
        $this->assertSame('RS256', $header['alg']);
        $this->assertArrayHasKey('n', $jwks['keys'][0]);
        $this->assertArrayHasKey('e', $jwks['keys'][0]);

        $verified = JWT::decode($body['id_token'], JWK::parseKeySet($jwks));
        $this->assertSame('https://idp.amtgard.com', $verified->iss);
        $this->assertSame(OidcTokenExchangeHarness::USER_ID, $verified->sub);
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
