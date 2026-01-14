<?php

namespace Amtgard\IdP\Controllers\Server;

use Amtgard\ActiveRecordOrm\EntityManager;
use Exception;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Stream;

class OAuth2ServerController
{
    protected AuthorizationServer $authorizationServer;
    protected ClientRepositoryInterface $clientRepository;
    protected ScopeRepositoryInterface $scopeRepository;
    protected UserRepositoryInterface $userRepository;
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger,
                                EntityManager   $entityManager,
                                AuthorizationServer $authorizationServer,
                                ClientRepositoryInterface $clientRepository,
                                ScopeRepositoryInterface $scopeRepository,
                                UserRepositoryInterface $userRepository)
    {
        $this->logger = $logger;
        $this->authorizationServer = $authorizationServer;
        $this->clientRepository = $clientRepository;
        $this->scopeRepository = $scopeRepository;
        $this->userRepository = $userRepository;
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

    public function approve(Request $request, Response $response): Response {

    }

    public function authorize(Request $request, Response $response): Response {
        try {

            if (!array_key_exists('authRequest', $_SESSION)) {
                $authRequest = $this->authorizationServer->validateAuthorizationRequest($request);
                $client = $this->clientRepository->getClientEntity($request->getQueryParams()['client_id']);
                $authRequest->setClient($client);
                $authRequest->setRedirectUri($client->getRedirectUri());
                $authRequest->setScopes([$this->scopeRepository->getScopeEntityByIdentifier(1)]);
                $_SESSION['authRequest'] = serialize($authRequest);
            } else {
                $authRequest = unserialize($_SESSION['authRequest']);
            }

            if (!array_key_exists('user_id', $_SESSION)) {
                $response = $response
                    ->withStatus(301)
                    ->withHeader('Location', '/auth/login?callback=authorize.php');
            } elseif (!array_key_exists('approved', $_SESSION)) {
                $authRequest->setUser(
                    $this->userRepository->getUserEntityById($_SESSION['user_id'])
                );

                $_SESSION['authRequest'] = serialize($authRequest);

                $response = $response
                    ->withStatus(301)
                    ->withHeader(
                        'Location',
                        'oauth/approve?scope='.urlencode(implode(',',
                            array_map(
                                fn($scope) => $scope->getIdentifier(), $authRequest->getScopes())
                        )).'&callback=/oauth/authorize'
                    );
            } else {
                $authRequest->setAuthorizationApproved(true);

                $response = $this->authorizationServer->completeAuthorizationRequest($authRequest, $response);

                session_destroy();
            }
        } catch (OAuthServerException $exception) {

            $response = $exception->generateHttpResponse($response);

        } catch (Exception $exception) {

            $response = $response->withStatus(500);
        }
        return $response;
    }

    public function authorizePost(Request $request, Response $response): Response {
        return $response;
    }

}