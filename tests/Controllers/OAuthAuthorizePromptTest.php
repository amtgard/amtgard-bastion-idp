<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Server\OAuth\OAuthAuthorizeAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthFlowErrorRenderer;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthSessionAuthRequestStore;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Twig\Environment as TwigEnvironment;

class OAuthAuthorizePromptTest extends TestCase
{
    private OAuthSessionAuthRequestStore $store;
    private AuthorizationServer $authorizationServer;
    private ClientRepository $clientRepository;
    private UserRepository $userRepository;
    private UserClientAuthorizationRepository $userClientAuthorizationRepository;
    private OAuthAuthorizeAction $action;
    private TwigEnvironment $view;

    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];

        $this->store = new OAuthSessionAuthRequestStore();
        $this->authorizationServer = $this->createMock(AuthorizationServer::class);
        $this->clientRepository = $this->createMock(ClientRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userClientAuthorizationRepository = $this->createMock(UserClientAuthorizationRepository::class);
        $this->view = $this->createMock(TwigEnvironment::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->action = OAuthAuthorizeAction::builder()
            ->authorizationServer($this->authorizationServer)
            ->clientRepository($this->clientRepository)
            ->userRepository($this->userRepository)
            ->userClientAuthorizationRepository($this->userClientAuthorizationRepository)
            ->authRequestStore($this->store)
            ->errorRenderer(OAuthFlowErrorRenderer::builder()->logger($logger)->view($this->view)->build())
            ->logger($logger)
            ->amtgardIdpJwt($this->createStub(AmtgardIdpJwt::class))
            ->redisCacheRepository($this->createStub(RedisCacheRepository::class))
            ->build();
    }

    public function testLoginRedirectUrlKeepsPrompt(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $authRequest = $this->authRequest($client);

        $this->authorizationServer->expects($this->once())
            ->method('validateAuthorizationRequest')
            ->willReturn($authRequest);

        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/oauth/authorize?prompt=login'
        )->withQueryParams(['prompt' => 'login']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(301, $result->getStatusCode());
        $location = $result->getHeaderLine('Location');
        $this->assertStringContainsString('/auth/login?redirect=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $loginQuery);
        $redirect = urldecode((string) ($loginQuery['redirect'] ?? ''));
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $authorizeQuery);

        $this->assertSame('login', $authorizeQuery['prompt'] ?? null);
        $this->assertSame('login', $this->store->prompt());
        $this->assertSame('/oauth/authorize?' . http_build_query(array_merge(
            $this->authorizeQueryWithoutPrompt($authRequest),
            ['prompt' => 'login']
        )), $this->action->buildPostAuthenticationRedirectUrl());
    }

    public function testBuildPostAuthenticationRedirectUrlOmitsPromptWhenAbsent(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->store->store($this->authRequest($client));

        $url = $this->action->buildPostAuthenticationRedirectUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('prompt', $query);
    }

    public function testPromptNonePlusOtherValueIsInvalidRequest(): void
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
            ->withQueryParams(['prompt' => 'none login']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(400, $result->getStatusCode());
        $this->assertFalse($this->store->hasPrompt());
        $this->assertFalse($this->store->hasAuthRequest());
    }

    public function testPromptLoginNoneIsInvalidRequest(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $this->view->method('render')->willReturn('error HTML');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'login none']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(400, $result->getStatusCode());
        $this->assertFalse($this->store->hasPrompt());
    }

    public function testNonStringPromptIsInvalidRequest(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $this->view->method('render')->willReturn('error HTML');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => ['none']]);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(400, $result->getStatusCode());
        $this->assertFalse($this->store->hasPrompt());
    }

    public function testPromptNoneWithoutSessionRedirectsWithLoginRequired(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $this->view->expects($this->never())->method('render');

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'none']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $result->getStatusCode());
        $location = $result->getHeaderLine('Location');
        $this->assertSame('http://test-redirect?error=login_required&state=test-state', $location);
        $this->assertStringNotContainsString('/auth/login', $location);
        $this->assertFalse($this->store->hasAuthRequest());
        $this->assertFalse($this->store->hasPrompt());
    }

    public function testPromptNoneWithoutSessionKeepsExistingRedirectQuery(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $authRequest = $this->authRequest($client);
        $authRequest->setRedirectUri('http://test-redirect?foo=1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($authRequest);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'none']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(
            'http://test-redirect?foo=1&error=login_required&state=test-state',
            $result->getHeaderLine('Location')
        );
    }

    public function testPromptNoneWithoutSessionOmitsEmptyState(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $authRequest = $this->authRequest($client);
        $authRequest->setState('');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($authRequest);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'none']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame('http://test-redirect?error=login_required', $result->getHeaderLine('Location'));
    }

    public function testPromptNoneWithStaleSessionRedirectsWithLoginRequired(): void
    {
        $_SESSION['user_id'] = 'stale-user';
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));
        $this->userRepository->method('getUserEntityById')->with('stale-user')->willReturn(null);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'none']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(
            'http://test-redirect?error=login_required&state=test-state',
            $result->getHeaderLine('Location')
        );
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertStringNotContainsString('/auth/login', $result->getHeaderLine('Location'));
    }

    public function testPromptNoneLoggedInWithoutConsentRedirectsWithConsentRequired(): void
    {
        $_SESSION['user_id'] = 'user-1';
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $user = $this->createMock(UserEntityInterface::class);
        $user->method('getIdentifier')->willReturn('user-1');

        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));
        $this->userRepository->method('getUserEntityById')->with('user-1')->willReturn($user);

        $clientRecord = new TestClient();
        $clientRecord->setId(456);
        $this->clientRepository->method('fetchBy')->with('identifier', 'client-1')->willReturn($clientRecord);
        $this->userClientAuthorizationRepository->method('hasAuthorization')->willReturn(false);
        $this->view->expects($this->never())->method('render');

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'none']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $result->getStatusCode());
        $location = $result->getHeaderLine('Location');
        $this->assertSame('http://test-redirect?error=consent_required&state=test-state', $location);
        $this->assertStringNotContainsString('/oauth/approve', $location);
        $this->assertSame('user-1', $_SESSION['user_id']);
        $this->assertFalse($this->store->hasAuthRequest());
        $this->assertFalse($this->store->hasPrompt());
    }

    public function testPromptLoginWhileLoggedOutStillReachesLogin(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'login']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(301, $result->getStatusCode());
        $this->assertStringContainsString('/auth/login?redirect=', $result->getHeaderLine('Location'));
        $this->assertSame('login', $this->store->prompt());
    }

    public function testPromptLoginLoggedInWithoutConsentStillReachesApprove(): void
    {
        $_SESSION['user_id'] = 'user-1';
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $user = $this->createMock(UserEntityInterface::class);
        $user->method('getIdentifier')->willReturn('user-1');

        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));
        $this->userRepository->method('getUserEntityById')->with('user-1')->willReturn($user);

        $clientRecord = new TestClient();
        $clientRecord->setId(456);
        $this->clientRepository->method('fetchBy')->with('identifier', 'client-1')->willReturn($clientRecord);
        $this->userClientAuthorizationRepository->method('hasAuthorization')->willReturn(false);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'login']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(301, $result->getStatusCode());
        $this->assertStringContainsString('/oauth/approve?', $result->getHeaderLine('Location'));
        $this->assertStringNotContainsString('consent_required', $result->getHeaderLine('Location'));
        $this->assertTrue($this->store->hasAuthRequest());
    }

    public function testOtherPromptValuesAreIgnoredAndStillReachLogin(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'login consent']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(301, $result->getStatusCode());
        $this->assertStringContainsString('/auth/login?redirect=', $result->getHeaderLine('Location'));
        $this->assertSame('login consent', $this->store->prompt());
    }

    public function testPromptNoneWithApprovedSessionFlagCompletesAuthorization(): void
    {
        $_SESSION['user_id'] = 'user-1';
        $_SESSION['approved'] = true;
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $user = $this->createMock(UserEntityInterface::class);
        $user->method('getIdentifier')->willReturn('user-1');
        $authRequest = $this->authRequest($client);

        $this->authorizationServer->method('validateAuthorizationRequest')->willReturn($authRequest);
        $this->userRepository->method('getUserEntityById')->with('user-1')->willReturn($user);
        $this->authorizationServer->expects($this->once())
            ->method('completeAuthorizationRequest')
            ->willReturn((new ResponseFactory())->createResponse(200));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'none']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringNotContainsString('consent_required', $result->getHeaderLine('Location'));
        $this->assertFalse($this->store->hasPrompt());
    }

    public function testPromptNoneWithPriorClientApprovalCompletesAuthorization(): void
    {
        $_SESSION['user_id'] = 'user-1';
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $user = $this->createMock(UserEntityInterface::class);
        $user->method('getIdentifier')->willReturn('user-1');

        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));
        $this->userRepository->method('getUserEntityById')->with('user-1')->willReturn($user);

        $clientRecord = new TestClient();
        $clientRecord->setId(456);
        $this->clientRepository->method('fetchBy')->with('identifier', 'client-1')->willReturn($clientRecord);
        $this->userClientAuthorizationRepository->expects($this->once())
            ->method('hasAuthorization')
            ->with('user-1', 456)
            ->willReturn(true);
        $this->authorizationServer->expects($this->once())
            ->method('completeAuthorizationRequest')
            ->willReturn((new ResponseFactory())->createResponse(200));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'none']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringNotContainsString('consent_required', $result->getHeaderLine('Location'));
    }

    public function testStoredAuthRequestDoesNotRecapturePrompt(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->store->store($this->authRequest($client));
        $this->store->storePrompt('login');

        $this->authorizationServer->expects($this->never())->method('validateAuthorizationRequest');

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'none login']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(301, $result->getStatusCode());
        $this->assertStringContainsString('/auth/login?redirect=', $result->getHeaderLine('Location'));
        $this->assertSame('login', $this->store->prompt());
    }

    public function testDuplicateNoneIsTreatedAsSilentPrompt(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => 'none none']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(
            'http://test-redirect?error=login_required&state=test-state',
            $result->getHeaderLine('Location')
        );
    }

    public function testWhitespaceOnlyPromptIsIgnored(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willReturn($this->authRequest($client));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/authorize')
            ->withQueryParams(['prompt' => '   ']);

        $result = $this->action->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(301, $result->getStatusCode());
        $this->assertStringContainsString('/auth/login?redirect=', $result->getHeaderLine('Location'));
        $this->assertSame('   ', $this->store->prompt());
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizeQueryWithoutPrompt(AuthorizationRequest $authRequest): array
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
