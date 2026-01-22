<?php

namespace Amtgard\IdP\Controllers\Server;

use Amtgard\ActiveRecordOrm\EntityManager;
use Exception;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Stream;
use Twig\Environment as TwigEnvironment;

class OAuth2ServerController
{
    protected AuthorizationServer $authorizationServer;
    protected ClientRepositoryInterface $clientRepository;
    protected ScopeRepositoryInterface $scopeRepository;
    protected UserRepositoryInterface $userRepository;
    protected TwigEnvironment $view;
    protected LoggerInterface $logger;
    protected ResourceServer $resourceServer;

    public function __construct(
        LoggerInterface $logger,
        TwigEnvironment $view,
        EntityManager $entityManager,
        AuthorizationServer $authorizationServer,
        ClientRepositoryInterface $clientRepository,
        ScopeRepositoryInterface $scopeRepository,
        UserRepositoryInterface $userRepository,
        ResourceServer $resourceServer
    ) {
        $this->logger = $logger;
        $this->view = $view;
        $this->authorizationServer = $authorizationServer;
        $this->clientRepository = $clientRepository;
        $this->scopeRepository = $scopeRepository;
        $this->userRepository = $userRepository;
        $this->resourceServer = $resourceServer;
    }

    public function token(Request $request, Response $response): Response
    {
        try {

            $response = $this->authorizationServer->respondToAccessTokenRequest($request, $response);

        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {

            $response = $exception->generateHttpResponse($response);

        } catch (\Exception $exception) {

            $body = new Stream(fopen('php://temp', 'r+'));
            $body->write($exception->getMessage());

            $response = $response->withStatus(500)->withBody($body);
        }
        return $response;
    }

    public function approve(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $action = $data['action'] ?? null;
            $callback = $data['callback'] ?? '/';

            if ($action === 'allow') {
                $_SESSION['approved'] = true;
                return $response
                    ->withStatus(302)
                    ->withHeader('Location', $callback);
            } else {
                // Deny action
                if (isset($_SESSION['authRequest'])) {
                    unset($_SESSION['authRequest']);
                }
                // Redirect to home or some error page
                return $response
                    ->withStatus(302)
                    ->withHeader('Location', '/');
            }
        }

        $queryParams = $request->getQueryParams();
        $scopeString = $queryParams['scope'] ?? '';
        $scopes = !empty($scopeString) ? explode(',', $scopeString) : [];
        $clientId = $queryParams['client_id'] ?? 'Unknown Application';
        $client = $this->clientRepository->getClientEntity($clientId);
        $callback = $queryParams['callback'] ?? '/';

        $response->getBody()->write(
            $this->view->render('oauth_approve.twig', [
                'client_name' => $client->getName(),
                'scopes' => $scopes,
                'callback' => $callback
            ])
        );

        return $response;
    }

    public function clearAuthentication(Request $request, Response $response): Response
    {
        if (isset($_SESSION['user_id'])) {
            unset($_SESSION['user_id']);
        }
        return $response;
    }

    public function clearAuthorizationAndApproval(Request $request, Response $response): Response
    {
        if (isset($_SESSION['authRequest'])) {
            unset($_SESSION['authRequest']);
        }
        if (isset($_SESSION['approved'])) {
            unset($_SESSION['approved']);
        }
        return $response;
    }

    public function authorize(Request $request, Response $response): Response
    {
        try {

            if (!array_key_exists('authRequest', $_SESSION)) {
                /** @var AuthorizationRequest $authRequest */
                $authRequest = $this->authorizationServer->validateAuthorizationRequest($request);
                $_SESSION['authRequest'] = serialize($authRequest);
            } else {
                $authRequest = unserialize($_SESSION['authRequest']);
            }

            if (!$this->userIsAuthenticated($authRequest)) {
                if (isset($_SESSION['user_id'])) {
                    $user = $this->userRepository->getUserEntityById($_SESSION['user_id']);
                    $authRequest->setUser($user);
                    $_SESSION['authRequest'] = serialize($authRequest);
                } else {
                    return $this->authenticateUser($response);
                }
            }

            if (!$this->clientAuthorizationIsApproved()) {
                return $this->requestUserAuthorizationOfClient($authRequest, $response);
            }

            return $this->finalizeAuthorization($authRequest, $response);
        } catch (OAuthServerException $exception) {

            $response = $exception->generateHttpResponse($response);

        } catch (Exception $exception) {

            $response = $response->withStatus(500);
        }
        return $response;
    }

    /**
     * Builds the redirect URL for the OAuth authorization flow after successful authentication.
     *
     * Sets the following query parameters:
     * - scope: Space-separated list of requested scopes (e.g., "profile email").
     * - state: The state parameter provided by the client to maintain state between the request and callback.
     * - response_type: Hardcoded to "code" for the authorization code flow.
     * - approval_prompt: Hardcoded to "auto".
     * - redirect_uri: The URI to redirect the user-agent to after authorization.
     * - client_id: The identifier of the client requesting authorization.
     * - code_challenge: The PKCE code challenge.
     * - code_challenge_method: The PKCE code challenge method (e.g., "S256").
     *
     * @return string The constructed redirect URL.
     */
    public function buildPostAuthenticationRedirectUrl()
    {
        // return "/oauth/authorize?scope=profile email&state=0ed589466400cc4e9c48319b11afe415&response_type=code&approval_prompt=auto&redirect_uri=https://edit.ocho.esdraelon.com&client_id=ork&code_challenge=47DEQpj8HBSa-_TImW-5JCeuQeRkm5NMpJWZG3hSuFU";

        /** @var AuthorizationRequest $authRequest */
        $authRequest = unserialize($_SESSION['authRequest']);

        $scopes = array_map(function ($scope) {
            return $scope->getIdentifier();
        }, $authRequest->getScopes());

        $params = [
            'scope' => implode(' ', $scopes),
            'state' => $authRequest->getState(),
            'response_type' => 'code',
            'approval_prompt' => 'auto',
            'redirect_uri' => $authRequest->getRedirectUri(),
            'client_id' => $authRequest->getClient()->getIdentifier(),
            'code_challenge' => $authRequest->getCodeChallenge(),
            'code_challenge_method' => $authRequest->getCodeChallengeMethod()
        ];

        return '/oauth/authorize?' . http_build_query($params);
    }

    private function userIsAuthenticated(AuthorizationRequest $authRequest)
    {
        return array_key_exists('user_id', $_SESSION) && !is_null($authRequest->getUser());
    }

    private function authenticateUser(Response $response)
    {
        $redirectUrl = $this->buildPostAuthenticationRedirectUrl();
        $response = $response
            ->withStatus(301)
            ->withHeader('Location', '/auth/login?redirect=' . urlencode($redirectUrl));
        return $response;
    }

    private function clientAuthorizationIsApproved()
    {
        return array_key_exists('approved', $_SESSION);
    }

    private function requestUserAuthorizationOfClient(AuthorizationRequest $authRequest, Response $response)
    {
        $authRequest->setUser(
            $this->userRepository->getUserEntityById($_SESSION['user_id'])
        );

        $_SESSION['authRequest'] = serialize($authRequest);

        $response = $response
            ->withStatus(301)
            ->withHeader(
                'Location',
                '/oauth/approve?scope=' . urlencode(implode(
                    ',',
                    array_map(
                        fn($scope) => $scope->getIdentifier(),
                        $authRequest->getScopes()
                    )
                )) . '&callback=/oauth/authorize&client_id=' . $authRequest->getClient()->getIdentifier()
            );
        return $response;
    }

    private function finalizeAuthorization(AuthorizationRequest $authRequest, Response $response)
    {
        $authRequest->setAuthorizationApproved(true);

        $response = $this->authorizationServer->completeAuthorizationRequest($authRequest, $response);

        session_destroy();

        return $response;
    }

    public function authorizePost(Request $request, Response $response): Response
    {
        return $response;
    }

}