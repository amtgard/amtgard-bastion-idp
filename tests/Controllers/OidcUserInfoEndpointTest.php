<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Server\OAuth\OidcUserInfoController;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Tests\Support\OidcTokenExchangeHarness;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class OidcUserInfoEndpointTest extends TestCase
{
    public function testAccessTokenWithOpenidAndEmailReturnsSubAndEmail(): void
    {
        $tokens = OidcTokenExchangeHarness::exchange('openid email');
        $this->assertArrayHasKey('access_token', $tokens);

        $response = $this->userinfo('GET', $tokens['access_token'], $this->harnessUser());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(
            [
                'sub' => OidcTokenExchangeHarness::USER_ID,
                'email' => 'ada@example.com',
            ],
            $this->json($response)
        );
    }

    public function testPostFormAccessTokenWithOpenidSucceeds(): void
    {
        $tokens = OidcTokenExchangeHarness::exchange('openid profile email');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/oauth/userinfo')
            ->withParsedBody(['access_token' => $tokens['access_token']]);

        $response = $this->controller($this->harnessUser())->userinfo(
            $request,
            (new ResponseFactory())->createResponse()
        );

        $body = $this->json($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(OidcTokenExchangeHarness::USER_ID, $body['sub']);
        $this->assertSame('ada@example.com', $body['email']);
        $this->assertSame('Ada Lovelace', $body['name']);
        $this->assertSame('ada', $body['preferred_username']);
        $this->assertArrayNotHasKey('aud', $body);
        $this->assertArrayNotHasKey('iss', $body);
        $this->assertArrayNotHasKey('exp', $body);
        $this->assertArrayNotHasKey('nonce', $body);
    }

    public function testAccessTokenWithoutOpenidReturns403(): void
    {
        $tokens = OidcTokenExchangeHarness::exchange('profile email');
        $response = $this->userinfo('GET', $tokens['access_token'], $this->harnessUser());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bearer error="insufficient_scope"', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame(['error' => 'insufficient_scope'], $this->json($response));
    }

    public function testAuthorizationJwtIsRejectedWith401(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $jwt = FirebaseJwtTestFactory::authorizationForUserAndClient(
            OidcTokenExchangeHarness::USER_ID,
            OidcTokenExchangeHarness::CLIENT_ID,
            null,
            '{"Statement":[]}'
        );

        $response = $this->userinfo('GET', $jwt, $this->harnessUser());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame(['error' => 'invalid_token'], $this->json($response));
    }

    private function userinfo(string $method, string $token, UserEntity $user): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/oauth/userinfo')
            ->withHeader('Authorization', 'Bearer ' . $token);

        return $this->controller($user)->userinfo($request, (new ResponseFactory())->createResponse());
    }

    private function controller(UserEntity $user): OidcUserInfoController
    {
        $tokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $tokens->method('isAccessTokenRevoked')->willReturn(false);

        $users = $this->createMock(UserRepository::class);
        $users->method('findUserByUserId')
            ->with(OidcTokenExchangeHarness::USER_ID)
            ->willReturn($user);

        return new OidcUserInfoController(
            new ResourceServer(
                $tokens,
                new CryptKey(OidcFixtureKeys::publicKeyPath(), null, false)
            ),
            $users
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function harnessUser(): UserEntity
    {
        return new class extends UserEntity {
            public function getUserId(): string
            {
                return OidcTokenExchangeHarness::USER_ID;
            }

            public function getEmail(): string
            {
                return 'ada@example.com';
            }

            public function getFirstName(): string
            {
                return 'Ada';
            }

            public function getLastName(): string
            {
                return 'Lovelace';
            }

            public function getUsername(): string
            {
                return 'ada';
            }

            public function getUpdatedAt(): ?\DateTime
            {
                return null;
            }
        };
    }
}
