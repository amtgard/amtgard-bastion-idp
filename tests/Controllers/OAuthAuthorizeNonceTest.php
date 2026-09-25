<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Server\OAuth\OAuthAuthorizeAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthFlowErrorRenderer;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthSessionAuthRequestStore;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Twig\Environment as TwigEnvironment;

class OAuthAuthorizeNonceTest extends TestCase
{
    private OAuthSessionAuthRequestStore $store;
    private AuthorizationServer $authorizationServer;
    private OAuthAuthorizeAction $action;
    private TwigEnvironment $view;

    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];

        $this->store = new OAuthSessionAuthRequestStore();
        $this->authorizationServer = $this->createMock(AuthorizationServer::class);
        $this->view = $this->createMock(TwigEnvironment::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->action = OAuthAuthorizeAction::builder()
            ->authorizationServer($this->authorizationServer)
            ->clientRepository($this->createStub(\League\OAuth2\Server\Repositories\ClientRepositoryInterface::class))
            ->userRepository($this->createStub(UserRepositoryInterface::class))
            ->userClientAuthorizationRepository($this->createStub(UserClientAuthorizationRepository::class))
            ->authRequestStore($this->store)
            ->errorRenderer(OAuthFlowErrorRenderer::builder()->logger($logger)->view($this->view)->build())
            ->logger($logger)
            ->amtgardIdpJwt($this->createStub(AmtgardIdpJwt::class))
            ->redisCacheRepository($this->createStub(RedisCacheRepository::class))
            ->build();
    }

    public function testLoginRedirectUrlKeepsNonce(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $authRequest = $this->authRequest($client);

        $this->authorizationServer->expects($this->once())
            ->method('validateAuthorizationRequest')
            ->willReturn($authRequest);

        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/oauth/authorize?nonce=rp-nonce-1'
        )->withQueryParams(['nonce' => 'rp-nonce-1']);
        $response = (new ResponseFactory())->createResponse();

        $result = $this->action->handle($request, $response);

        $this->assertSame(301, $result->getStatusCode());
        $location = $result->getHeaderLine('Location');
        $this->assertStringContainsString('/auth/login?redirect=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $loginQuery);
        $redirect = urldecode((string) ($loginQuery['redirect'] ?? ''));
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $authorizeQuery);

        $this->assertSame('rp-nonce-1', $authorizeQuery['nonce'] ?? null);
        $this->assertSame('rp-nonce-1', $this->store->nonce());
        $this->assertSame('/oauth/authorize?' . http_build_query(array_merge(
            $this->authorizeQueryWithoutNonce($authRequest),
            ['nonce' => 'rp-nonce-1']
        )), $this->action->buildPostAuthenticationRedirectUrl());
    }

    public function testBuildPostAuthenticationRedirectUrlOmitsNonceWhenAbsent(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->store->store($this->authRequest($client));

        $url = $this->action->buildPostAuthenticationRedirectUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('nonce', $query);
    }

    public function testNonceLongerThan255IsInvalidRequest(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $this->view->expects($this->once())
            ->method('render')
            ->with('oauth_error.twig', $this->callback(function (array $context) {
                return $context['is_protocol_error'] === true;
            }))
            ->willReturn('error HTML');

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['nonce' => str_repeat('n', 256)]);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(400, $result->getStatusCode());
        $this->assertFalse($this->store->hasNonce());
        $this->assertFalse($this->store->hasAuthRequest());
    }

    public function testEmptyNonceIsInvalidRequest(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $this->view->method('render')->willReturn('error HTML');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['nonce' => '']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(400, $result->getStatusCode());
        $this->assertFalse($this->store->hasNonce());
    }

    public function testNonStringNonceIsInvalidRequest(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $this->view->method('render')->willReturn('error HTML');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['nonce' => ['x']]);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(400, $result->getStatusCode());
        $this->assertFalse($this->store->hasNonce());
    }

    public function testNonceOf255CharactersIsAccepted(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $nonce = str_repeat('n', 255);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['nonce' => $nonce]);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(301, $result->getStatusCode());
        $this->assertSame($nonce, $this->store->nonce());
    }

    public function testStoredAuthRequestDoesNotRecaptureNonce(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->store->store($this->authRequest($client));
        $this->store->storeNonce('already-stored');

        $this->authorizationServer->expects($this->never())->method('validateAuthorizationRequest');

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['nonce' => str_repeat('x', 256)]);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(301, $result->getStatusCode());
        $this->assertSame('already-stored', $this->store->nonce());
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizeQueryWithoutNonce(AuthorizationRequest $authRequest): array
    {
        return [
            'scope' => implode(' ', array_map(
                static fn ($scope) => $scope->getIdentifier(),
                $authRequest->getScopes()
            )),
            'state' => $authRequest->getState(),
            'response_type' => 'code',
            'approval_prompt' => 'auto',
            'redirect_uri' => $authRequest->getRedirectUri(),
            'client_id' => $authRequest->getClient()->getIdentifier(),
            'code_challenge' => $authRequest->getCodeChallenge(),
            'code_challenge_method' => $authRequest->getCodeChallengeMethod(),
        ];
    }

    private function authRequest(ClientEntityInterface $client): AuthorizationRequest
    {
        $authRequest = new AuthorizationRequest();
        $authRequest->setClient($client);
        $authRequest->setScopes([]);
        $authRequest->setState('test-state');
        $authRequest->setRedirectUri('http://test-redirect');
        $authRequest->setCodeChallenge('test-challenge');
        $authRequest->setCodeChallengeMethod('S256');

        return $authRequest;
    }
}
