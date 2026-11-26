<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models;

use Amtgard\IdP\Models\Oidc\OidcAuthCodeGrant;
use Amtgard\IdP\Models\Oidc\OidcIdTokenResponse;
use Amtgard\IdP\Models\Oidc\OidcNonceContext;
use Amtgard\IdP\Utility\JwksFactory;
use Amtgard\IdP\Utility\OAuthKeyMaterial;
use Amtgard\Traits\Builder\Builder;
use Lcobucci\JWT\Signer;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use OpenIDConnectServer\ClaimExtractor;
use OpenIDConnectServer\Repositories\IdentityProviderInterface;

class OAuthServerConfiguration
{
    use Builder;

    private ClientRepositoryInterface $clientRepository;
    private ScopeRepositoryInterface $scopeRepository;
    private AccessTokenRepositoryInterface $accessTokenRepository;
    private AuthCodeRepositoryInterface $authCodeRepository;
    private RefreshTokenRepositoryInterface $refreshTokenRepository;
    private IdentityProviderInterface $identityProvider;
    private ?OidcNonceContext $nonceContext = null;

    /**
     * Signer injected from the container-bound Signer (§5.1a),
     * passed to the OIDC ID-token response type (ES256 design
     * §5.2). Null until the builder sets it; build() then leaves
     * the response type without a signer and getExtraParams()
     * fails fast (D7).
     */
    private ?Signer $signer = null;

    public function build(): AuthorizationServer
    {
        $nonceContext = $this->nonceContext ?? new OidcNonceContext();

        $privateKeyPath = OAuthKeyMaterial::pathFromEnv('OAUTH_PRIVATE_KEY');
        $privateKey = new CryptKey(
            $privateKeyPath,
            null,
            false
        );

        $kid = JwksFactory::fromPublicKeyPem(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY'))->kid();

        $idTokenResponse = new OidcIdTokenResponse(
            $this->identityProvider,
            new ClaimExtractor(),
            $kid,
            $nonceContext
        );
        if ($this->signer !== null) {
            $idTokenResponse->setSigner($this->signer);
        }

        // Setup the authorization server
        $server = new AuthorizationServer(
            $this->clientRepository,
            $this->accessTokenRepository,
            $this->scopeRepository,
            $privateKey,
            $_ENV['AUTH_SERVER_DEFUSE_KEY'],
            $idTokenResponse
        );

        // Enable the authentication code grant on the server with a token TTL of 1 hour
        $authCodeGrant = new OidcAuthCodeGrant(
            $this->authCodeRepository,
            $this->refreshTokenRepository,
            new \DateInterval($_ENV['OAUTH_AUTH_TOKEN_TTL']), // Authorization codes will expire after 10 minutes
            $nonceContext
        );
        // Refresh tokens will expire after 1 month
        $authCodeGrant->setRefreshTokenTTL(new \DateInterval($_ENV['OAUTH_REFRESH_TOKEN_TTL']));

        // Enable the refresh token grant on the server
        $refreshTokenGrant = new RefreshTokenGrant($this->refreshTokenRepository);
        // Refresh tokens will expire after 1 month
        $refreshTokenGrant->setRefreshTokenTTL(new \DateInterval($_ENV['OAUTH_REFRESH_TOKEN_TTL']));

        $server->enableGrantType(
            $authCodeGrant,
            new \DateInterval($_ENV['OAUTH_ACCESS_TOKEN_TTL']) // Access tokens will expire after 1 hour
        );

        $server->enableGrantType(
            $refreshTokenGrant,
            new \DateInterval($_ENV['OAUTH_ACCESS_TOKEN_TTL']) // Access tokens will expire after 1 hour
        );

        return $server;
    }
}
