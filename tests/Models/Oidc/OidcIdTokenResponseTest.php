<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models\Oidc;

use Amtgard\IdP\Models\Oidc\IdentityRepository;
use Amtgard\IdP\Models\Oidc\OidcIdTokenResponse;
use Amtgard\IdP\Models\Oidc\OidcNonceContext;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\JwksFactory;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use OpenIDConnectServer\ClaimExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OidcIdTokenResponseTest extends TestCase
{
    private string $previousAppUrl;
    private string $previousHttpHost;

    protected function setUp(): void
    {
        $this->previousAppUrl = $_ENV['APP_URL'] ?? '';
        $this->previousHttpHost = $_SERVER['HTTP_HOST'] ?? '';
        $_ENV['APP_URL'] = 'https://idp.amtgard.com/';
        $_SERVER['HTTP_HOST'] = 'evil.example';
    }

    protected function tearDown(): void
    {
        if ($this->previousAppUrl === '') {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->previousAppUrl;
        }

        if ($this->previousHttpHost === '') {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->previousHttpHost;
        }
    }

    public function testIdTokenVerifiesIssuerAudienceSubjectAndExpiry(): void
    {
        $expiry = (new DateTimeImmutable())->add(new \DateInterval('PT1H'));
        $kid = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicEcPem())->kid();
        $params = $this->extraParams(
            $this->accessToken(['openid', 'profile', 'email'], $expiry),
            $kid
        );

        $this->assertArrayHasKey('id_token', $params);
        $decoded = JWT::decode($params['id_token'], new Key(OidcFixtureKeys::publicEcPem(), 'ES256'));

        $this->assertSame('https://idp.amtgard.com', $decoded->iss);
        $this->assertSame('test-client', $this->audience($decoded->aud));
        $this->assertSame('uuid-1', $decoded->sub);
        $this->assertSame($expiry->getTimestamp(), $decoded->exp);
        $this->assertSame($kid, $this->header($params['id_token'])['kid']);
        $this->assertSame('ES256', $this->header($params['id_token'])['alg']);
        $this->assertObjectHasProperty('iat', $decoded);
        $this->assertSame('user@example.com', $decoded->email);
        $this->assertSame('Ada Lovelace', $decoded->name);
        $this->assertObjectNotHasProperty('nonce', $decoded);
    }

    public function testIssuerIgnoresRequestHostAndTrailingSlash(): void
    {
        $_ENV['APP_URL'] = 'https://idp.amtgard.com/';
        $_SERVER['HTTP_HOST'] = 'other-host.example';

        $params = $this->extraParams($this->accessToken(['openid']));
        $decoded = JWT::decode($params['id_token'], new Key(OidcFixtureKeys::publicEcPem(), 'ES256'));

        $this->assertSame('https://idp.amtgard.com', $decoded->iss);
        $this->assertNotSame('https://other-host.example', $decoded->iss);
    }

    public function testMissingAppUrlYieldsEmptyIssuer(): void
    {
        unset($_ENV['APP_URL']);

        $params = $this->extraParams($this->accessToken(['openid']));
        $decoded = JWT::decode($params['id_token'], new Key(OidcFixtureKeys::publicEcPem(), 'ES256'));

        $this->assertSame('', $decoded->iss);
    }

    public function testWithoutOpenidThereIsNoIdToken(): void
    {
        $params = $this->extraParams($this->accessToken(['profile', 'email']));

        $this->assertSame([], $params);
        $this->assertArrayNotHasKey('id_token', $params);
    }

    public function testGetExtraParamsWithoutSignerThrows(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('getUserEntityById')->with('uuid-1')->willReturn($this->oauthUser());

        $response = new OidcIdTokenResponse(
            new IdentityRepository($users),
            new ClaimExtractor(),
            JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicEcPem())->kid()
        );
        $response->setPrivateKey(new CryptKey(OidcFixtureKeys::privateEcPem(), null, false));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OIDC ID token signer was not injected.');

        $method = new ReflectionMethod(OidcIdTokenResponse::class, 'getExtraParams');
        $method->invoke($response, $this->accessToken(['openid']));
    }

    public function testGetExtraParamsReadsKeyFileWhenContentsAreEmpty(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('getUserEntityById')->with('uuid-1')->willReturn($this->oauthUser());

        $response = new OidcIdTokenResponse(
            new IdentityRepository($users),
            new ClaimExtractor(),
            JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicEcPem())->kid()
        );
        // League's CryptKey always populates contents from a file, so
        // the file-path branch is only reachable with empty contents;
        // the double below simulates that (its path still points at
        // the real key file). It is built from inline PEM on purpose:
        // CryptKey's file-path constructor checks
        // is_readable('file://...'), which the BypassFinals stream
        // wrapper enabled by the test bootstrap reports as false.
        $key = new class (OidcFixtureKeys::privateEcPem(), null, false) extends CryptKey {
            public function getKeyContents(): string
            {
                return '';
            }

            public function getKeyPath(): string
            {
                return OidcFixtureKeys::privateEcKeyPath();
            }
        };
        $response->setPrivateKey($key);
        $response->setSigner(new EcdsaSha256());

        $method = new ReflectionMethod(OidcIdTokenResponse::class, 'getExtraParams');
        $params = $method->invoke($response, $this->accessToken(['openid', 'profile', 'email']));
        $this->assertIsArray($params);
        $this->assertArrayHasKey('id_token', $params);

        $decoded = JWT::decode($params['id_token'], new Key(OidcFixtureKeys::publicEcPem(), 'ES256'));
        $this->assertSame('ES256', $this->header($params['id_token'])['alg']);
        $this->assertSame('uuid-1', $decoded->sub);
    }

    public function testIdTokenIncludesNonceFromContextAndThenClearsIt(): void
    {
        $context = new OidcNonceContext();
        $context->set('rp-nonce-value');

        $params = $this->extraParams($this->accessToken(['openid']), null, $context);
        $decoded = JWT::decode($params['id_token'], new Key(OidcFixtureKeys::publicEcPem(), 'ES256'));

        $this->assertSame('rp-nonce-value', $decoded->nonce);
        $this->assertNull($context->get());
    }

    public function testEmptyNonceContextDoesNotAddNonceClaim(): void
    {
        $context = new OidcNonceContext();
        $params = $this->extraParams($this->accessToken(['openid']), null, $context);
        $decoded = JWT::decode($params['id_token'], new Key(OidcFixtureKeys::publicEcPem(), 'ES256'));

        $this->assertObjectNotHasProperty('nonce', $decoded);
        $this->assertNull($context->get());
    }

    public function testEmptyStringNonceDoesNotAddNonceClaimAndClearsContext(): void
    {
        $context = new OidcNonceContext();
        $context->set('');
        $params = $this->extraParams($this->accessToken(['openid']), null, $context);
        $decoded = JWT::decode($params['id_token'], new Key(OidcFixtureKeys::publicEcPem(), 'ES256'));

        $this->assertObjectNotHasProperty('nonce', $decoded);
        $this->assertNull($context->get());
    }

    /**
     * @param list<string> $scopeIds
     */
    private function extraParams(
        AccessTokenEntityInterface $accessToken,
        ?string $kid = null,
        ?OidcNonceContext $nonceContext = null
    ): array {
        $users = $this->createMock(UserRepository::class);
        $users->method('getUserEntityById')->with('uuid-1')->willReturn($this->oauthUser());

        $response = new OidcIdTokenResponse(
            new IdentityRepository($users),
            new ClaimExtractor(),
            $kid ?? JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicEcPem())->kid(),
            $nonceContext
        );
        $response->setPrivateKey(new CryptKey(OidcFixtureKeys::privateEcPem(), null, false));
        $response->setSigner(new EcdsaSha256());

        $method = new ReflectionMethod(OidcIdTokenResponse::class, 'getExtraParams');

        return $method->invoke($response, $accessToken);
    }

    /**
     * @param list<string> $scopeIds
     */
    private function accessToken(array $scopeIds, ?DateTimeImmutable $expiry = null): AccessTokenEntityInterface
    {
        $scopes = [];
        foreach ($scopeIds as $scopeId) {
            $scope = $this->createMock(ScopeEntityInterface::class);
            $scope->method('getIdentifier')->willReturn($scopeId);
            $scopes[] = $scope;
        }

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('test-client');

        $accessToken = $this->createMock(AccessTokenEntityInterface::class);
        $accessToken->method('getScopes')->willReturn($scopes);
        $accessToken->method('getClient')->willReturn($client);
        $accessToken->method('getUserIdentifier')->willReturn('uuid-1');
        $accessToken->method('getExpiryDateTime')->willReturn(
            $expiry ?? (new DateTimeImmutable())->add(new \DateInterval('PT1H'))
        );

        return $accessToken;
    }

    private function oauthUser(): OAuthUser
    {
        $user = new class extends UserEntity {
            public function getUserId(): string
            {
                return 'uuid-1';
            }

            public function getEmail(): string
            {
                return 'user@example.com';
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

        return OAuthUser::builder()
            ->identifier('uuid-1')
            ->userEntity($user)
            ->build();
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

        return json_decode($this->base64UrlDecode($parts[0]), true, 512, JSON_THROW_ON_ERROR);
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        $this->assertNotFalse($decoded);

        return $decoded;
    }
}
