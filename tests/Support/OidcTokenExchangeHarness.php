<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- Pre-existing:
// this test-support file defines the OIDC harness doubles in one file.
// Splitting them into one file per class is out of scope for the ES256
// milestones; the M7 change here switches the harness fixtures to EC and
// mints access tokens through the production OAuthAccessToken entity.

use Amtgard\IdP\Controllers\Server\OAuth\OAuthFlowErrorRenderer;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthSessionAuthRequestStore;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthTokenAction;
use Amtgard\IdP\Models\OAuthServerConfiguration;
use Amtgard\IdP\Utility\OAuthKeyMaterial;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use Amtgard\IdP\Models\Oidc\IdentityRepository;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthAccessToken;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Amtgard\IdP\Persistence\Server\Repositories\AuthCodeNonceLookup;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;

final class OidcTokenExchangeHarness
{
    public const CLIENT_ID = 'oidc-test-client';
    public const CLIENT_SECRET = 'oidc-test-secret';
    public const REDIRECT_URI = 'https://rp.example/cb';
    public const USER_ID = '11111111-1111-1111-1111-111111111111';

    public static function exchange(string $scopeList, ?string $nonce = null): array
    {
        $previous = self::snapshotEnvironment();
        self::configureEnvironment();

        try {
            return self::runTokenRequest($scopeList, $nonce);
        } finally {
            self::restoreEnvironment($previous);
        }
    }

    public static function refresh(string $refreshToken): array
    {
        $previous = self::snapshotEnvironment();
        self::configureEnvironment();

        try {
            $server = self::buildServer();
            $tokenRequest = (new ServerRequestFactory())
                ->createServerRequest('POST', '/oauth/token')
                ->withHeader('Host', 'evil.example')
                ->withHeader('Authorization', 'Basic ' . base64_encode(self::CLIENT_ID . ':' . self::CLIENT_SECRET))
                ->withParsedBody([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);

            return self::handleTokenRequest($server, $tokenRequest);
        } finally {
            self::restoreEnvironment($previous);
        }
    }

    private static function runTokenRequest(string $scopeList, ?string $nonce): array
    {
        $client = new OidcHarnessClient();
        $user = self::oauthUser();
        $server = self::buildServer();

        $scopes = [];
        foreach (preg_split('/\s+/', trim($scopeList)) ?: [] as $scopeId) {
            if ($scopeId === '') {
                continue;
            }
            $scope = new OidcHarnessScope();
            $scope->setIdentifier($scopeId);
            $scopes[] = $scope;
        }

        $authRequest = new AuthorizationRequest();
        $authRequest->setGrantTypeId('authorization_code');
        $authRequest->setClient($client);
        $authRequest->setUser($user);
        $authRequest->setRedirectUri(self::REDIRECT_URI);
        $authRequest->setScopes($scopes);
        $authRequest->setAuthorizationApproved(true);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $nonceStore = new OAuthSessionAuthRequestStore();
        if ($nonce !== null) {
            $nonceStore->storeNonce($nonce);
        } else {
            $nonceStore->clearNonce();
        }

        $authorizeResponse = $server->completeAuthorizationRequest(
            $authRequest,
            (new ResponseFactory())->createResponse()
        );
        $location = $authorizeResponse->getHeaderLine('Location');
        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        if (!isset($query['code']) || !is_string($query['code'])) {
            throw new \RuntimeException('Authorization response did not include a code: ' . $location);
        }

        $tokenRequest = (new ServerRequestFactory())
            ->createServerRequest('POST', '/oauth/token')
            ->withHeader('Host', 'evil.example')
            ->withHeader('Authorization', 'Basic ' . base64_encode(self::CLIENT_ID . ':' . self::CLIENT_SECRET))
            ->withParsedBody([
                'grant_type' => 'authorization_code',
                'code' => $query['code'],
                'redirect_uri' => self::REDIRECT_URI,
            ]);

        return self::handleTokenRequest($server, $tokenRequest);
    }

    private static function buildServer(): \League\OAuth2\Server\AuthorizationServer
    {
        $client = new OidcHarnessClient();
        $user = self::oauthUser();
        $identity = new IdentityRepository(self::userRepository($user));

        return OAuthServerConfiguration::builder()
            ->clientRepository(new OidcHarnessClientRepository($client))
            ->scopeRepository(new OidcHarnessScopeRepository())
            ->accessTokenRepository(new OidcHarnessAccessTokenRepository(self::signerFromEnvironment()))
            ->authCodeRepository(new OidcHarnessAuthCodeRepository())
            ->refreshTokenRepository(new OidcHarnessRefreshTokenRepository())
            ->identityProvider($identity)
            ->signer(self::signerFromEnvironment())
            ->build()
            ->build();
    }

    /**
     * Resolve the lcobucci Signer for the configured private key,
     * with the same key → implementation mapping as the
     * container's Signer binding (ES256 design §5.1a). The harness
     * builds its server outside the container, so it resolves the
     * signer itself; the endpoint tests mint with the configured
     * key's algorithm (EC since M7 switched the harness fixtures).
     */
    private static function signerFromEnvironment(): Signer
    {
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
    }

    private static function handleTokenRequest(
        \League\OAuth2\Server\AuthorizationServer $server,
        \Psr\Http\Message\ServerRequestInterface $tokenRequest
    ): array {
        $errorRenderer = OAuthFlowErrorRenderer::builder()
            ->idpLogging(\Amtgard\IdP\Tests\Utility\Log\IdpLoggingTestFactory::create())
            ->view(new TwigEnvironment(new ArrayLoader([])))
            ->build();

        $action = OAuthTokenAction::builder()
            ->authorizationServer($server)
            ->errorRenderer($errorRenderer)
            ->idpLogging(\Amtgard\IdP\Tests\Utility\Log\IdpLoggingTestFactory::create())
            ->build();

        $response = $action->handle($tokenRequest, (new ResponseFactory())->createResponse());
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Token response was not JSON: ' . $body);
        }

        return $decoded;
    }

    /**
     * @return array{env: array<string, mixed>, host: mixed}
     */
    private static function snapshotEnvironment(): array
    {
        $keys = [
            'APP_URL',
            'OAUTH_PRIVATE_KEY',
            'OAUTH_PUBLIC_KEY',
            'AUTH_SERVER_DEFUSE_KEY',
            'OAUTH_AUTH_TOKEN_TTL',
            'OAUTH_REFRESH_TOKEN_TTL',
            'OAUTH_ACCESS_TOKEN_TTL',
        ];
        $env = [];
        foreach ($keys as $key) {
            $env[$key] = $_ENV[$key] ?? null;
        }

        return [
            'env' => $env,
            'host' => $_SERVER['HTTP_HOST'] ?? null,
        ];
    }

    /**
     * @param array{env: array<string, mixed>, host: mixed} $previous
     */
    private static function restoreEnvironment(array $previous): void
    {
        foreach ($previous['env'] as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        if ($previous['host'] === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $previous['host'];
        }
    }

    public static function configureEnvironment(): void
    {
        $_ENV['APP_URL'] = 'https://idp.amtgard.com/';
        $_SERVER['HTTP_HOST'] = 'evil.example';
        $_ENV['OAUTH_PRIVATE_KEY'] = OidcFixtureKeys::privateEcKeyPath();
        $_ENV['OAUTH_PUBLIC_KEY'] = OidcFixtureKeys::publicEcKeyPath();
        $_ENV['AUTH_SERVER_DEFUSE_KEY'] = str_repeat('a', 64);
        $_ENV['OAUTH_AUTH_TOKEN_TTL'] = 'PT10M';
        $_ENV['OAUTH_REFRESH_TOKEN_TTL'] = 'P1M';
        $_ENV['OAUTH_ACCESS_TOKEN_TTL'] = 'PT1H';
    }

    private static function oauthUser(): OAuthUser
    {
        $user = new class extends UserEntity {
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

        return OAuthUser::builder()
            ->identifier(self::USER_ID)
            ->userEntity($user)
            ->build();
    }

    private static function userRepository(OAuthUser $user): UserRepository
    {
        return new class ($user) extends UserRepository {
            public function __construct(private OAuthUser $oauthUser)
            {
            }

            public function getUserEntityById(string $userIdentifier): ?UserEntityInterface
            {
                return $userIdentifier === $this->oauthUser->getIdentifier()
                    ? $this->oauthUser
                    : null;
            }
        };
    }
}

final class OidcHarnessClient implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    public function __construct()
    {
        $this->identifier = OidcTokenExchangeHarness::CLIENT_ID;
        $this->name = 'OIDC test client';
        $this->redirectUri = OidcTokenExchangeHarness::REDIRECT_URI;
        $this->isConfidential = true;
    }
}

final class OidcHarnessScope implements ScopeEntityInterface
{
    use EntityTrait;
    use ScopeTrait;
}

final class OidcHarnessAuthCode implements AuthCodeEntityInterface
{
    use AuthCodeTrait;
    use TokenEntityTrait;
    use EntityTrait;
}

final class OidcHarnessRefreshToken implements RefreshTokenEntityInterface
{
    use RefreshTokenTrait;
    use EntityTrait;
}

final class OidcHarnessClientRepository implements ClientRepositoryInterface
{
    public function __construct(private OidcHarnessClient $client)
    {
    }

    public function getClientEntity($clientIdentifier): ?ClientEntityInterface
    {
        return $clientIdentifier === $this->client->getIdentifier() ? $this->client : null;
    }

    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        return $clientIdentifier === OidcTokenExchangeHarness::CLIENT_ID
            && $clientSecret === OidcTokenExchangeHarness::CLIENT_SECRET;
    }
}

final class OidcHarnessScopeRepository implements ScopeRepositoryInterface
{
    public function getScopeEntityByIdentifier($identifier): ?ScopeEntityInterface
    {
        if (!in_array($identifier, ['openid', 'email', 'profile'], true)) {
            return null;
        }

        $scope = new OidcHarnessScope();
        $scope->setIdentifier($identifier);

        return $scope;
    }

    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null
    ): array {
        return $scopes;
    }
}

final class OidcHarnessAccessTokenRepository implements AccessTokenRepositoryInterface
{
    /**
     * Signer resolved from the configured private key (ES256 design §5.1):
     * passed to the production OAuthAccessToken entity builder so the
     * harness mints through the real entity — including its signer
     * override — instead of a test double that bypasses it. M7: the
     * harness fixtures are EC, so the minted access token is ES256.
     */
    public function __construct(private ?Signer $signer = null)
    {
    }

    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        $userIdentifier = null
    ): AccessTokenEntityInterface {
        return OAuthAccessToken::builder()
            ->client($clientEntity)
            ->signer($this->signer)
            ->scopes($scopes)
            ->userIdentifier($userIdentifier)
            ->build();
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
    }

    public function revokeAccessToken($tokenId): void
    {
    }

    public function isAccessTokenRevoked($tokenId): bool
    {
        return false;
    }
}

final class OidcHarnessAuthCodeRepository implements AuthCodeRepositoryInterface, AuthCodeNonceLookup
{
    /** @var array<string, bool> */
    private array $revoked = [];

    /** @var array<string, string> */
    private array $nonces = [];

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new OidcHarnessAuthCode();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $this->revoked[$authCodeEntity->getIdentifier()] = false;
        $store = new OAuthSessionAuthRequestStore();
        $nonce = $store->nonce();
        if (is_string($nonce) && $nonce !== '') {
            $this->nonces[$authCodeEntity->getIdentifier()] = $nonce;
        }
        $store->clearNonce();
    }

    public function findNonceByAuthCodeId(string $authCodeId): ?string
    {
        return $this->nonces[$authCodeId] ?? null;
    }

    public function revokeAuthCode($codeId): void
    {
        $this->revoked[$codeId] = true;
    }

    public function isAuthCodeRevoked($codeId): bool
    {
        return $this->revoked[$codeId] ?? false;
    }
}

final class OidcHarnessRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new OidcHarnessRefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
    }

    public function revokeRefreshToken($tokenId): void
    {
    }

    public function isRefreshTokenRevoked($tokenId): bool
    {
        return false;
    }
}
