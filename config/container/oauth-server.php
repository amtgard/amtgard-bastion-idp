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
use Amtgard\IdP\Models\OAuthServerConfiguration;
use Amtgard\IdP\Models\Oidc\IdentityRepository;
use Amtgard\IdP\Models\Oidc\OidcNonceContext;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\JwksFactory;
use Amtgard\IdP\Utility\OAuthKeyMaterial;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
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
use Psr\Log\LoggerInterface;
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

    JwksFactory::class => function () {
        return JwksFactory::fromPublicKeyPem(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY'));
    },

    DiscoveryController::class => function (ContainerInterface $container) {
        return new DiscoveryController(
            $container->get(JwksFactory::class),
            (string) ($_ENV['APP_URL'] ?? ''),
        );
    },

    OidcUserInfoController::class => function (ContainerInterface $container) {
        return new OidcUserInfoController(
            $container->get(ResourceServer::class),
            $container->get(UserRepository::class),
        );
    },

    AmtgardIdpJwt::class => function (ContainerInterface $container) {
        return AmtgardIdpJwt::builder()
            ->assembler($container->get(AuthorizationJwtAssembler::class))
            ->redisCacheRepository($container->get(RedisCacheRepository::class))
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
            ->build();
    },

    AuthorizationServer::class => function (ContainerInterface $container) {
        return $container->get(OAuthServerConfiguration::class)->build();
    },

    OAuthFlowErrorRenderer::class => function (ContainerInterface $container) {
        return OAuthFlowErrorRenderer::builder()
            ->logger($container->get(LoggerInterface::class))
            ->view($container->get(TwigEnvironment::class))
            ->build();
    },

    OAuthTokenAction::class => function (ContainerInterface $container) {
        return OAuthTokenAction::builder()
            ->authorizationServer($container->get(AuthorizationServer::class))
            ->errorRenderer($container->get(OAuthFlowErrorRenderer::class))
            ->build();
    },

    OAuthApproveAction::class => function (ContainerInterface $container) {
        return OAuthApproveAction::builder()
            ->clientRepository($container->get(ClientRepositoryInterface::class))
            ->userClientAuthorizationRepository($container->get(UserClientAuthorizationRepository::class))
            ->authRequestStore($container->get(OAuthSessionAuthRequestStore::class))
            ->errorRenderer($container->get(OAuthFlowErrorRenderer::class))
            ->view($container->get(TwigEnvironment::class))
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
            ->logger($container->get(LoggerInterface::class))
            ->amtgardIdpJwt($container->get(AmtgardIdpJwt::class))
            ->redisCacheRepository($container->get(RedisCacheRepository::class))
            ->build();
    },

    ResourceServer::class => function (ContainerInterface $container) {
        $publicKey = new CryptKey(
            $_ENV['OAUTH_PUBLIC_KEY'],
            null,
            false
        );

        return new ResourceServer(
            $container->get(AccessTokenRepositoryInterface::class),
            $publicKey
        );
    },
];
