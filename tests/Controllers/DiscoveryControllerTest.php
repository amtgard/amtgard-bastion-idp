<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Server\OAuth\DiscoveryController;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\JwksFactory;
use DI\Bridge\Slim\Bridge;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class DiscoveryControllerTest extends TestCase
{
    private const CACHE_CONTROL = 'public, max-age=3600';

    public function testOpenidConfigurationMatchesDevelopmentDocument(): void
    {
        $response = $this->controller('https://idp.amtgard.com')->openidConfiguration(
            (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/openid-configuration'),
            (new ResponseFactory())->createResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(self::CACHE_CONTROL, $response->getHeaderLine('Cache-Control'));
        $this->assertSame($this->expectedDiscovery('https://idp.amtgard.com'), $this->json($response));
    }

    public function testOpenidConfigurationTrimsTrailingSlashesFromAppUrl(): void
    {
        $response = $this->controller('https://idp.amtgard.com///')->openidConfiguration(
            (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/openid-configuration'),
            (new ResponseFactory())->createResponse()
        );

        $this->assertSame($this->expectedDiscovery('https://idp.amtgard.com'), $this->json($response));
    }

    public function testOpenidConfigurationRtrimKeepsALeadingSlash(): void
    {
        $response = $this->controller('/https://idp.amtgard.com/')->openidConfiguration(
            (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/openid-configuration'),
            (new ResponseFactory())->createResponse()
        );

        $this->assertSame($this->expectedDiscovery('/https://idp.amtgard.com'), $this->json($response));
    }

    public function testJwksReturnsOnePublicRsaKeyAndNoPrivateParameters(): void
    {
        $factory = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem());
        $response = $this->controller('https://idp.amtgard.com', $factory)->jwks(
            (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/jwks.json'),
            (new ResponseFactory())->createResponse()
        );

        $body = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(self::CACHE_CONTROL, $response->getHeaderLine('Cache-Control'));
        $this->assertSame($factory->document(), $body);
        $this->assertCount(1, $body['keys']);
        $this->assertSame(
            ['kty', 'use', 'alg', 'kid', 'n', 'e'],
            array_keys($body['keys'][0])
        );
        $this->assertSame('RSA', $body['keys'][0]['kty']);
        $this->assertSame('sig', $body['keys'][0]['use']);
        $this->assertSame('RS256', $body['keys'][0]['alg']);
        $this->assertSame($factory->kid(), $body['keys'][0]['kid']);
        foreach (['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth'] as $private) {
            $this->assertArrayNotHasKey($private, $body['keys'][0]);
        }
    }

    public function testWellKnownRoutesArePublicGets(): void
    {
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/config/routes.php')($app);
        $collector = $app->getRouteCollector();

        $discovery = $collector->getNamedRoute('oidc.discovery');
        $jwks = $collector->getNamedRoute('oidc.jwks');

        $this->assertSame(['GET'], $discovery->getMethods());
        $this->assertSame(['GET'], $jwks->getMethods());
        $this->assertSame('/.well-known/openid-configuration', $discovery->getPattern());
        $this->assertSame('/.well-known/jwks.json', $jwks->getPattern());
        $this->assertSame(
            [DiscoveryController::class, 'openidConfiguration'],
            $discovery->getCallable()
        );
        $this->assertSame(
            [DiscoveryController::class, 'jwks'],
            $jwks->getCallable()
        );
        $this->assertSame([], $discovery->getGroups());
        $this->assertSame([], $jwks->getGroups());
    }

    public function testSlimDispatchesDiscoveryAndJwks(): void
    {
        $factory = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem());
        $builder = new ContainerBuilder();
        $builder->useAutowiring(false);
        $builder->addDefinitions([
            DiscoveryController::class => new DiscoveryController($factory, 'https://idp.amtgard.com/'),
        ]);
        $app = Bridge::create($builder->build());
        (require dirname(__DIR__, 2) . '/config/routes.php')($app);

        $discovery = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/openid-configuration')
        );
        $jwks = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/jwks.json')
        );

        $this->assertSame(200, $discovery->getStatusCode());
        $this->assertSame(self::CACHE_CONTROL, $discovery->getHeaderLine('Cache-Control'));
        $this->assertSame($this->expectedDiscovery('https://idp.amtgard.com'), $this->json($discovery));

        $this->assertSame(200, $jwks->getStatusCode());
        $this->assertSame(self::CACHE_CONTROL, $jwks->getHeaderLine('Cache-Control'));
        $this->assertSame($factory->document(), $this->json($jwks));
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedDiscovery(string $issuer): array
    {
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'userinfo_endpoint' => $issuer . '/oauth/userinfo',
            'jwks_uri' => $issuer . '/.well-known/jwks.json',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
                'none',
            ],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => [
                'sub',
                'iss',
                'aud',
                'exp',
                'iat',
                'nonce',
                'email',
                'name',
                'preferred_username',
                'updated_at',
            ],
        ];
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

    private function controller(string $appUrl, ?JwksFactory $factory = null): DiscoveryController
    {
        return new DiscoveryController(
            $factory ?? JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicPem()),
            $appUrl
        );
    }
}
