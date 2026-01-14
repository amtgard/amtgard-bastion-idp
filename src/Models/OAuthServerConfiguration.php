<?php

namespace Amtgard\IdP\Models;

use Amtgard\Traits\Builder\Builder;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

class OAuthServerConfiguration
{
    use Builder;

    private ClientRepositoryInterface $clientRepository;
    private ScopeRepositoryInterface $scopeRepository;
    private AccessTokenRepositoryInterface $accessTokenRepository;
    private AuthCodeRepositoryInterface $authCodeRepository;
    private RefreshTokenRepositoryInterface $refreshTokenRepository;

    public function build(): AuthorizationServer {
        // Path to private key
        $privateKey = new CryptKey(
            $_ENV['OAUTH_PRIVATE_KEY'],
            null,
            false
        );

        // Setup the authorization server
        $server = new AuthorizationServer(
            $this->clientRepository,
            $this->accessTokenRepository,
            $this->scopeRepository,
            $privateKey,
            $_ENV['AUTH_SERVER_DEFUSE_KEY']
        );

        // Enable the authentication code grant on the server with a token TTL of 1 hour
        $authCodeGrant = new AuthCodeGrant(
            $this->authCodeRepository,
            $this->refreshTokenRepository,
            new \DateInterval('PT10M') // Authorization codes will expire after 10 minutes
        );
        $authCodeGrant->setRefreshTokenTTL(new \DateInterval('P1M')); // Refresh tokens will expire after 1 month

        // Enable the refresh token grant on the server
        $refreshTokenGrant = new RefreshTokenGrant($this->refreshTokenRepository);
        $refreshTokenGrant->setRefreshTokenTTL(new \DateInterval('P1M')); // Refresh tokens will expire after 1 month

        $server->enableGrantType(
            $authCodeGrant,
            new \DateInterval('PT1H') // Access tokens will expire after 1 hour
        );

        $server->enableGrantType(
            $refreshTokenGrant,
            new \DateInterval('PT1H') // Access tokens will expire after 1 hour
        );

        return $server;
    }
}