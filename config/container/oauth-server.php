<?php

declare(strict_types=1);

use Amtgard\IdP\Controllers\Server\OAuth\DiscoveryController;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthApproveAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthAuthorizeAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthFlowErrorRenderer;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthSessionAuthRequestStore;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthTokenAction;
use Amtgard\IdP\Controllers\Server\OAuth\OidcUserInfoController;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Models\AuthorizationJwtValidator;
use Amtgard\IdP\Models\CompositeAuthorizationJwtValidator;
use Amtgard\IdP\Models\OAuthServerConfiguration;
use Amtgard\IdP\Models\Oidc\CompositeBearerTokenValidator;
use Amtgard\IdP\Models\Oidc\IdentityRepository;
use Amtgard\IdP\Models\Oidc\OidcNonceContext;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\JwksFactory;
use Amtgard\IdP\Utility\OAuthKeyMaterial;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use OpenIDConnectServer\Repositories\IdentityProviderInterface;
use Psr\Container\ContainerInterface;
use Twig\Environment as TwigEnvironment;

return [
    IdentityRepository::class => function (ContainerInterface $container) {
        return new IdentityRepository($container->get(UserRepository::class));
    },

    IdentityProviderInterface::class => function (ContainerInterface $container) {
        return $container->get(IdentityRepository::class);
    },

    OidcNonceContext::class => function () {
        return new OidcNonceContext();
    },

    // ES256 design §5.1a: the single place that maps key → signer
    // implementation. Minting paths inject Signer and never know which
    // algorithm is active. Any other key type or curve throws here, at
    // container-build time.
    Signer::class => function () {
        $pem = OAuthKeyMaterial::readFromEnv('OAUTH_PRIVATE_KEY');
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('Unable to parse OAuth private key for signer binding.');
        }

        $details = openssl_pkey_get_details($key);
        if (\is_array($details) && isset($details['rsa'])) {
            return new RsaSha256();
        }

        if (
            \is_array($details)
            && isset($details['ec']['curve_name'])
            && $details['ec']['curve_name'] === 'prime256v1'
        ) {
            return new EcdsaSha256();
        }

        throw new \RuntimeException('OAuth signing key must be RSA or prime256v1 EC.');
    },

    // ES256 design §5.5/§5.7: the container binds the JwkProvider list —
    // the current key from OAUTH_PUBLIC_KEY, current first, plus the
    // retiring key from OAUTH_PUBLIC_KEY_PREVIOUS when set (mapped from
    // oauth-public.key.previous in config/load-env.php). Absence of the
    // previous key means a single-key JWKS.
    JwksFactory::class => function () {
        $providers = [
            JwksFactory::providerFromPublicKeyPem(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')),
        ];

        $previous = $_ENV['OAUTH_PUBLIC_KEY_PREVIOUS'] ?? getenv('OAUTH_PUBLIC_KEY_PREVIOUS');
        if (is_string($previous) && $previous !== '') {
            $providers[] = JwksFactory::providerFromPublicKeyPem(
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY_PREVIOUS')
            );
        }

        return JwksFactory::fromProviders(...$providers);
    },

    DiscoveryController::class => function (ContainerInterface $container) {
        return new DiscoveryController(
            $container->get(JwksFactory::class),
            (string) ($_ENV['APP_URL'] ?? ''),
        );
    },

    // ES256 design §5.4: the authorization-JWT validator composite, built
    // from the environment — current key from OAUTH_PUBLIC_KEY, retiring
    // key from OAUTH_PUBLIC_KEY_PREVIOUS when set (mapped from
    // oauth-public.key.previous in config/load-env.php, §5.7). ES256
    // current first, then RS256 during overlap.
    AuthorizationJwtValidator::class => function () {
        return CompositeAuthorizationJwtValidator::fromEnvironment();
    },

    OidcUserInfoController::class => function (ContainerInterface $container) {
        return new OidcUserInfoController(
            $container->get(ResourceServer::class),
            $container->get(UserRepository::class),
            $container->get(AuthorizationJwtValidator::class),
        );
    },

    AmtgardIdpJwt::class => function (ContainerInterface $container) {
        return AmtgardIdpJwt::builder()
            ->assembler($container->get(AuthorizationJwtAssembler::class))
            ->redisCacheRepository($container->get(RedisCacheRepository::class))
            ->idpLogging($container->get(\Amtgard\IdP\Utility\Log\IdpLogging::class))
            ->signer($container->get(Signer::class))
            ->build();
    },

    OAuthServerConfiguration::class => function (ContainerInterface $container) {
        return OAuthServerConfiguration::builder()
            ->clientRepository($container->get(ClientRepositoryInterface::class))
            ->scopeRepository($container->get(ScopeRepositoryInterface::class))
            ->accessTokenRepository($container->get(AccessTokenRepositoryInterface::class))
            ->authCodeRepository($container->get(AuthCodeRepositoryInterface::class))
            ->refreshTokenRepository($container->get(RefreshTokenRepositoryInterface::class))
            ->identityProvider($container->get(IdentityProviderInterface::class))
            ->nonceContext($container->get(OidcNonceContext::class))
            ->signer($container->get(Signer::class))
            ->build();
    },

    AuthorizationServer::class => function (ContainerInterface $container) {
        return $container->get(OAuthServerConfiguration::class)->build();
    },

    OAuthFlowErrorRenderer::class => function (ContainerInterface $container) {
        return OAuthFlowErrorRenderer::builder()
            ->idpLogging($container->get(\Amtgard\IdP\Utility\Log\IdpLogging::class))
            ->view($container->get(TwigEnvironment::class))
            ->build();
    },

    OAuthTokenAction::class => function (ContainerInterface $container) {
        return OAuthTokenAction::builder()
            ->authorizationServer($container->get(AuthorizationServer::class))
            ->errorRenderer($container->get(OAuthFlowErrorRenderer::class))
            ->idpLogging($container->get(\Amtgard\IdP\Utility\Log\IdpLogging::class))
            ->build();
    },

    OAuthApproveAction::class => function (ContainerInterface $container) {
        return OAuthApproveAction::builder()
            ->clientRepository($container->get(ClientRepositoryInterface::class))
            ->userClientAuthorizationRepository($container->get(UserClientAuthorizationRepository::class))
            ->authRequestStore($container->get(OAuthSessionAuthRequestStore::class))
            ->errorRenderer($container->get(OAuthFlowErrorRenderer::class))
            ->view($container->get(TwigEnvironment::class))
            ->idpLogging($container->get(\Amtgard\IdP\Utility\Log\IdpLogging::class))
            ->build();
    },

    OAuthAuthorizeAction::class => function (ContainerInterface $container) {
        return OAuthAuthorizeAction::builder()
            ->authorizationServer($container->get(AuthorizationServer::class))
            ->clientRepository($container->get(ClientRepositoryInterface::class))
            ->userRepository($container->get(UserRepositoryInterface::class))
            ->userClientAuthorizationRepository($container->get(UserClientAuthorizationRepository::class))
            ->authRequestStore($container->get(OAuthSessionAuthRequestStore::class))
            ->errorRenderer($container->get(OAuthFlowErrorRenderer::class))
            ->idpLogging($container->get(\Amtgard\IdP\Utility\Log\IdpLogging::class))
            ->amtgardIdpJwt($container->get(AmtgardIdpJwt::class))
            ->redisCacheRepository($container->get(RedisCacheRepository::class))
            ->build();
    },

    // ES256 design §5.3: the ResourceServer validates access tokens with
    // the composite bearer-token validator (current key from
    // OAUTH_PUBLIC_KEY, retiring key from OAUTH_PUBLIC_KEY_PREVIOUS when
    // set — mapped from oauth-public.key.previous in config/load-env.php,
    // §5.7). The CryptKey is still passed: League only calls
    // setPublicKey() on BearerTokenValidator instances, so the composite
    // never receives it — it gets its keys through its own constructor.
    ResourceServer::class => function (ContainerInterface $container) {
        $publicKey = new CryptKey(
            $_ENV['OAUTH_PUBLIC_KEY'],
            null,
            false
        );

        $accessTokenRepository = $container->get(AccessTokenRepositoryInterface::class);

        return new ResourceServer(
            $accessTokenRepository,
            $publicKey,
            CompositeBearerTokenValidator::fromEnvironment(
                $accessTokenRepository,
                $container->get(\Amtgard\IdP\Utility\Log\IdpLogging::class)->oauth()
            )
        );
    },
];
