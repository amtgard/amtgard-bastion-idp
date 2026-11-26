<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Controllers\Server\OAuth\DiscoveryController;
use Amtgard\IdP\Controllers\Server\OAuth\OidcUserInfoController;
use Amtgard\IdP\Models\OAuthServerConfiguration;
use Amtgard\IdP\Models\Oidc\IdentityRepository;
use Amtgard\IdP\Models\Oidc\OidcNonceContext;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Repositories\AccessTokenRepository;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\JwksFactory;
use DI\ContainerBuilder;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use League\OAuth2\Server\ResourceServer;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use OpenIDConnectServer\Repositories\IdentityProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class OidcContainerWiringTest extends TestCase
{
    public function testIdentityRepositoryFactoryAdaptsUserRepository(): void
    {
        $users = $this->createStub(UserRepository::class);
        $container = $this->containerReturning([
            UserRepository::class => $users,
        ]);

        $definitions = require __DIR__ . '/../../config/container.php';
        $identity = $definitions[IdentityRepository::class]($container);

        $this->assertInstanceOf(IdentityRepository::class, $identity);
        $this->assertInstanceOf(IdentityProviderInterface::class, $identity);
    }

    public function testIdentityProviderInterfaceAliasReturnsIdentityRepository(): void
    {
        $identity = new IdentityRepository($this->createStub(UserRepository::class));
        $container = $this->containerReturning([
            IdentityRepository::class => $identity,
        ]);

        $definitions = require __DIR__ . '/../../config/container.php';

        $this->assertSame($identity, $definitions[IdentityProviderInterface::class]($container));
    }

    public function testNonceContextFactoryReturnsRequestScopedHolder(): void
    {
        $definitions = require __DIR__ . '/../../config/container.php';

        $this->assertInstanceOf(OidcNonceContext::class, $definitions[OidcNonceContext::class]());
    }

    public function testJwksFactoryFactoryReadsOauthPublicKey(): void
    {
        $previous = $_ENV['OAUTH_PUBLIC_KEY'] ?? null;
        $_ENV['OAUTH_PUBLIC_KEY'] = OidcFixtureKeys::publicKeyPath();

        try {
            $definitions = require __DIR__ . '/../../config/container.php';
            $factory = $definitions[JwksFactory::class]();

            $this->assertInstanceOf(JwksFactory::class, $factory);
            $this->assertSame(
                JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem())->kid(),
                $factory->kid()
            );
        } finally {
            if ($previous === null) {
                unset($_ENV['OAUTH_PUBLIC_KEY']);
            } else {
                $_ENV['OAUTH_PUBLIC_KEY'] = $previous;
            }
        }
    }

    public function testJwksFactoryFactoryReadsEcOauthPublicKey(): void
    {
        $previous = $_ENV['OAUTH_PUBLIC_KEY'] ?? null;
        $_ENV['OAUTH_PUBLIC_KEY'] = OidcFixtureKeys::publicEcKeyPath();

        try {
            $definitions = require __DIR__ . '/../../config/container.php';
            $factory = $definitions[JwksFactory::class]();

            $this->assertInstanceOf(JwksFactory::class, $factory);
            $this->assertSame(
                JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicEcPem())->kid(),
                $factory->kid()
            );
            $document = $factory->document();
            $this->assertCount(1, $document['keys']);
            $this->assertSame('EC', $document['keys'][0]['kty']);
            $this->assertSame('ES256', $document['keys'][0]['alg']);
        } finally {
            if ($previous === null) {
                unset($_ENV['OAUTH_PUBLIC_KEY']);
            } else {
                $_ENV['OAUTH_PUBLIC_KEY'] = $previous;
            }
        }
    }

    public function testDiscoveryControllerFactoryUsesJwksFactoryAndAppUrl(): void
    {
        $jwks = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem());
        $previous = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://idp.amtgard.com/';
        $container = $this->containerReturning([
            JwksFactory::class => $jwks,
        ]);

        try {
            $definitions = require __DIR__ . '/../../config/container.php';
            $controller = $definitions[DiscoveryController::class]($container);

            $this->assertInstanceOf(DiscoveryController::class, $controller);
            $this->assertSame(
                $jwks,
                (new \ReflectionProperty(DiscoveryController::class, 'jwksFactory'))->getValue($controller)
            );
            $this->assertSame(
                'https://idp.amtgard.com/',
                (new \ReflectionProperty(DiscoveryController::class, 'appUrl'))->getValue($controller)
            );
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_URL']);
            } else {
                $_ENV['APP_URL'] = $previous;
            }
        }
    }

    public function testOidcUserInfoControllerFactoryUsesResourceServerAndUsers(): void
    {
        $resourceServer = $this->createStub(ResourceServer::class);
        $users = $this->createStub(UserRepository::class);
        $container = $this->containerReturning([
            ResourceServer::class => $resourceServer,
            UserRepository::class => $users,
        ]);

        $definitions = require __DIR__ . '/../../config/container.php';
        $controller = $definitions[OidcUserInfoController::class]($container);

        $this->assertInstanceOf(OidcUserInfoController::class, $controller);
        $this->assertSame(
            $resourceServer,
            (new \ReflectionProperty(OidcUserInfoController::class, 'resourceServer'))->getValue($controller)
        );
        $this->assertSame(
            $users,
            (new \ReflectionProperty(OidcUserInfoController::class, 'userRepository'))->getValue($controller)
        );
    }

    public function testOAuthServerConfigurationFactoryReceivesIdentityProvider(): void
    {
        $identity = new IdentityRepository($this->createStub(UserRepository::class));
        $nonceContext = new OidcNonceContext();
        $signer = new EcdsaSha256();
        $container = $this->containerReturning([
            Signer::class => $signer,
            ClientRepositoryInterface::class => $this->createStub(ClientRepositoryInterface::class),
            ScopeRepositoryInterface::class => $this->createStub(ScopeRepositoryInterface::class),
            AccessTokenRepositoryInterface::class => $this->createStub(AccessTokenRepositoryInterface::class),
            AuthCodeRepositoryInterface::class => $this->createStub(AuthCodeRepositoryInterface::class),
            RefreshTokenRepositoryInterface::class => $this->createStub(RefreshTokenRepositoryInterface::class),
            IdentityProviderInterface::class => $identity,
            OidcNonceContext::class => $nonceContext,
        ]);

        $definitions = require __DIR__ . '/../../config/container.php';
        $config = $definitions[OAuthServerConfiguration::class]($container);

        $this->assertInstanceOf(OAuthServerConfiguration::class, $config);
        $this->assertSame(
            $identity,
            (new \ReflectionProperty(OAuthServerConfiguration::class, 'identityProvider'))->getValue($config)
        );
        $this->assertSame(
            $nonceContext,
            (new \ReflectionProperty(OAuthServerConfiguration::class, 'nonceContext'))->getValue($config)
        );
        $this->assertSame(
            $signer,
            (new \ReflectionProperty(OAuthServerConfiguration::class, 'signer'))->getValue($config)
        );
    }

    /**
     * ES256 design §3.5, §5.1: the AccessTokenRepositoryInterface factory
     * wraps the ORM construction and injects the container-bound Signer
     * via setSigner(). Resolving through a real PHP-DI container proves
     * the two-argument factory (EntityManager + ContainerInterface)
     * is invoked with both arguments.
     */
    public function testAccessTokenRepositoryFactoryInjectsContainerSigner(): void
    {
        $repository = new AccessTokenRepository();
        $em = $this->createStub(EntityManager::class);
        $em->method('getRepository')->willReturn($repository);
        $signer = new EcdsaSha256();

        $builder = new ContainerBuilder();
        $builder->addDefinitions(require __DIR__ . '/../../config/container/persistence.php');
        // Overrides AFTER the persistence definitions: later definitions
        // win in PHP-DI, so these stubs must come second.
        $builder->addDefinitions([
            EntityManager::class => fn () => $em,
            Signer::class => fn () => $signer,
        ]);
        $container = $builder->build();

        $resolved = $container->get(AccessTokenRepositoryInterface::class);

        $this->assertSame($repository, $resolved);
        $this->assertSame(
            $signer,
            (new \ReflectionProperty(AccessTokenRepository::class, 'signer'))->getValue($resolved)
        );
    }

    /**
     * @param array<class-string, object> $services
     */
    private function containerReturning(array $services): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($services): object {
                $this->assertArrayHasKey($id, $services);

                return $services[$id];
            }
        );

        return $container;
    }
}
