<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models;

use Amtgard\IdP\Models\OAuthServerConfiguration;
use Amtgard\IdP\Models\Oidc\OidcAuthCodeGrant;
use Amtgard\IdP\Models\Oidc\OidcIdTokenResponse;
use Amtgard\IdP\Models\Oidc\OidcNonceContext;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\JwksFactory;
use DateInterval;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use OpenIDConnectServer\Repositories\IdentityProviderInterface;
use PHPUnit\Framework\TestCase;

class OAuthServerConfigurationTest extends TestCase
{
    private string $loosePrivateKey;

    /** @var array<string, mixed> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        foreach ([
            'OAUTH_PRIVATE_KEY',
            'OAUTH_PUBLIC_KEY',
            'AUTH_SERVER_DEFUSE_KEY',
            'OAUTH_AUTH_TOKEN_TTL',
            'OAUTH_REFRESH_TOKEN_TTL',
            'OAUTH_ACCESS_TOKEN_TTL',
        ] as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? null;
        }

        $this->loosePrivateKey = tempnam(sys_get_temp_dir(), 'oidc-priv-');
        $this->assertNotFalse($this->loosePrivateKey);
        $this->assertNotFalse(file_put_contents($this->loosePrivateKey, OidcFixtureKeys::privatePem()));
        chmod($this->loosePrivateKey, 0644);

        $_ENV['OAUTH_PRIVATE_KEY'] = $this->loosePrivateKey;
        $_ENV['OAUTH_PUBLIC_KEY'] = OidcFixtureKeys::publicKeyPath();
        $_ENV['AUTH_SERVER_DEFUSE_KEY'] = str_repeat('a', 64);
        $_ENV['OAUTH_AUTH_TOKEN_TTL'] = 'PT10M';
        $_ENV['OAUTH_REFRESH_TOKEN_TTL'] = 'P2M';
        $_ENV['OAUTH_ACCESS_TOKEN_TTL'] = 'PT1H';
    }

    protected function tearDown(): void
    {
        if (is_string($this->loosePrivateKey) && file_exists($this->loosePrivateKey)) {
            unlink($this->loosePrivateKey);
        }

        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testBuildWiresOidcResponseGrantKidAndTokenTtls(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            if (str_contains($message, 'permissions are not correct')) {
                throw new \ErrorException($message, 0, $severity);
            }

            return false;
        });
        try {
            $server = $this->configuration()->build();
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(AuthorizationServer::class, $server);

        $responseType = (new \ReflectionProperty($server, 'responseType'))->getValue($server);
        $this->assertInstanceOf(OidcIdTokenResponse::class, $responseType);
        $this->assertSame(
            JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem())->kid(),
            (new \ReflectionProperty($responseType, 'keyIdentifier'))->getValue($responseType)
        );

        $grants = (new \ReflectionProperty($server, 'enabledGrantTypes'))->getValue($server);
        $this->assertInstanceOf(OidcAuthCodeGrant::class, $grants['authorization_code']);
        $this->assertInstanceOf(RefreshTokenGrant::class, $grants['refresh_token']);
        $grantContext = (new \ReflectionProperty(OidcAuthCodeGrant::class, 'nonceContext'))
            ->getValue($grants['authorization_code']);
        $responseContext = (new \ReflectionProperty(OidcIdTokenResponse::class, 'nonceContext'))
            ->getValue($responseType);
        $this->assertInstanceOf(OidcNonceContext::class, $grantContext);
        $this->assertSame($grantContext, $responseContext);
        $this->assertCount(2, $grants);

        $this->assertEquals(
            new DateInterval('P2M'),
            (new \ReflectionProperty($grants['authorization_code'], 'refreshTokenTTL'))->getValue($grants['authorization_code'])
        );
        $this->assertEquals(
            new DateInterval('P2M'),
            (new \ReflectionProperty($grants['refresh_token'], 'refreshTokenTTL'))->getValue($grants['refresh_token'])
        );
    }

    public function testBuildUsesInjectedNonceContextOnGrantAndResponse(): void
    {
        $nonceContext = new OidcNonceContext();
        set_error_handler(static function (int $severity, string $message): bool {
            if (str_contains($message, 'permissions are not correct')) {
                throw new \ErrorException($message, 0, $severity);
            }

            return false;
        });
        try {
            $server = $this->configuration($nonceContext)->build();
        } finally {
            restore_error_handler();
        }

        $responseType = (new \ReflectionProperty($server, 'responseType'))->getValue($server);
        $grants = (new \ReflectionProperty($server, 'enabledGrantTypes'))->getValue($server);
        $this->assertSame(
            $nonceContext,
            (new \ReflectionProperty(OidcAuthCodeGrant::class, 'nonceContext'))->getValue($grants['authorization_code'])
        );
        $this->assertSame(
            $nonceContext,
            (new \ReflectionProperty(OidcIdTokenResponse::class, 'nonceContext'))->getValue($responseType)
        );
    }

    private function configuration(?OidcNonceContext $nonceContext = null): OAuthServerConfiguration
    {
        $builder = OAuthServerConfiguration::builder()
            ->clientRepository($this->createStub(ClientRepositoryInterface::class))
            ->scopeRepository($this->createStub(ScopeRepositoryInterface::class))
            ->accessTokenRepository($this->createStub(AccessTokenRepositoryInterface::class))
            ->authCodeRepository($this->createStub(AuthCodeRepositoryInterface::class))
            ->refreshTokenRepository($this->createStub(RefreshTokenRepositoryInterface::class))
            ->identityProvider($this->createStub(IdentityProviderInterface::class));
        if ($nonceContext !== null) {
            $builder->nonceContext($nonceContext);
        }

        return $builder->build();
    }
}
