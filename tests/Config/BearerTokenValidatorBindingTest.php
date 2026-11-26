<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Models\Oidc\CompositeBearerTokenValidator;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Tests\Utility\Log\IdpLoggingTestFactory;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Firebase\JWT\JWT as FirebaseJwt;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * M6 container wiring (ES256 design §5.3): the ResourceServer factory
 * passes the CompositeBearerTokenValidator built from the environment as
 * the 3rd constructor argument.
 */
class BearerTokenValidatorBindingTest extends TestCase
{
    public function testResourceServerFactoryWiresOverlapComposite(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PUBLIC_KEY' => OidcFixtureKeys::publicEcKeyPath(),
            'OAUTH_PUBLIC_KEY_PREVIOUS' => OidcFixtureKeys::publicKeyPath(),
        ]);

        try {
            $resourceServer = $this->buildResourceServer();

            $validator = $this->authorizationValidator($resourceServer);
            $this->assertInstanceOf(CompositeBearerTokenValidator::class, $validator);

            $es256 = $resourceServer->validateAuthenticatedRequest(
                $this->bearerRequest($this->es256AccessToken())
            );
            $this->assertSame('user-1', $es256->getAttribute('oauth_user_id'));

            $rs256 = $resourceServer->validateAuthenticatedRequest(
                $this->bearerRequest($this->rs256FixtureAccessToken())
            );
            $this->assertSame('user-1', $rs256->getAttribute('oauth_user_id'));
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    public function testResourceServerFactoryWithoutPreviousKeyIsSingleKey(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PUBLIC_KEY' => OidcFixtureKeys::publicEcKeyPath(),
            'OAUTH_PUBLIC_KEY_PREVIOUS' => null,
        ]);

        try {
            $resourceServer = $this->buildResourceServer();

            $es256 = $resourceServer->validateAuthenticatedRequest(
                $this->bearerRequest($this->es256AccessToken())
            );
            $this->assertSame('user-1', $es256->getAttribute('oauth_user_id'));

            try {
                $resourceServer->validateAuthenticatedRequest(
                    $this->bearerRequest($this->rs256FixtureAccessToken())
                );
                $this->fail('Expected OAuthServerException (access denied) to be thrown.');
            } catch (OAuthServerException $exception) {
                $payload = $exception->getPayload();
                $this->assertSame('access_denied', $payload['error']);
                $this->assertSame('Access token could not be verified', $payload['hint'] ?? null);
            }
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    private function buildResourceServer(): ResourceServer
    {
        $repository = $this->createStub(AccessTokenRepositoryInterface::class);
        $repository->method('isAccessTokenRevoked')->willReturn(false);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id) => match ($id) {
                AccessTokenRepositoryInterface::class => $repository,
                IdpLogging::class => IdpLoggingTestFactory::create(),
                default => throw new \RuntimeException("Unexpected container get({$id})"),
            }
        );

        $definitions = require __DIR__ . '/../../config/container/oauth-server.php';
        $resourceServer = $definitions[ResourceServer::class]($container);
        $this->assertInstanceOf(ResourceServer::class, $resourceServer);

        return $resourceServer;
    }

    private function authorizationValidator(ResourceServer $resourceServer): mixed
    {
        $method = new \ReflectionMethod(ResourceServer::class, 'getAuthorizationValidator');
        $method->setAccessible(true);

        return $method->invoke($resourceServer);
    }

    private function bearerRequest(string $token): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/resources/userinfo')
            ->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function es256AccessToken(int $ttlSeconds = 3600): string
    {
        $now = time();

        return FirebaseJwtTestFactory::encodeEs256([
            'aud' => 'client-1',
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'scopes' => ['profile', 'email'],
        ]);
    }

    /**
     * RS256 access token signed with the OIDC RSA fixture key (not the
     * dev-keys pair): validates against OidcFixtureKeys::publicPem().
     */
    private function rs256FixtureAccessToken(int $ttlSeconds = 3600): string
    {
        $now = time();

        return FirebaseJwt::encode([
            'aud' => 'client-1',
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'scopes' => ['profile', 'email'],
        ], OidcFixtureKeys::privatePem(), 'RS256');
    }

    /**
     * @param array<string, string|null> $overrides
     * @return array<string, array{env: mixed, putenv: mixed}>
     */
    private function overrideOAuthEnv(array $overrides): array
    {
        $saved = [];
        foreach ($overrides as $key => $value) {
            $saved[$key] = ['env' => $_ENV[$key] ?? null, 'putenv' => getenv($key)];
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }

        return $saved;
    }

    /** @param array<string, array{env: mixed, putenv: mixed}> $saved */
    private function restoreOAuthEnv(array $saved): void
    {
        foreach ($saved as $key => $previous) {
            if ($previous['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previous['env'];
            }
            if ($previous['putenv'] === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $previous['putenv']);
            }
        }
    }
}
