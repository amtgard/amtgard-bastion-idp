<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Client\AuthController;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;
use Slim\Routing\RouteParser;

class AuthControllerTest extends TestCase
{
    private $entityManager;
    private $logger;
    private $googleProvider;
    private $facebookProvider;
    private $authController;
    private $request;
    private $response;
    private $stream;
    private $routeContext;
    private $routeParser;
    private $userRepository;

    protected function setUp(): void
    {
        // Create mocks for classes that can be mocked
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->googleProvider = $this->createMock(Google::class);
        $this->facebookProvider = $this->createMock(Facebook::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);
        $this->routeParser = $this->createMock(RouteParser::class);
        $this->userRepository = $this->createMock(EntityRepository::class);

        // Setup response with stream
        $this->response->method('getBody')->willReturn($this->stream);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withStatus')->willReturnSelf();

        // Instead of mocking RouteContext, create the necessary routing attributes
        $routingResults = $this->createMock(\Slim\Routing\RoutingResults::class);
        $routeParser = $this->createMock(\Slim\Routing\RouteParser::class);

        // Set up the request attributes for routing
        $this->request->method('getAttribute')
            ->willReturnCallback(function ($name) use ($routingResults, $routeParser) {
                if ($name === RouteContext::ROUTING_RESULTS) {
                    return $routingResults;
                }
                if ($name === RouteContext::ROUTE_PARSER) {
                    return $routeParser;
                }
                return null;
            });

        // Create controller
        $this->authController = new AuthController(
            $this->entityManager,
            $this->logger,
            $this->googleProvider,
            $this->facebookProvider
        );

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        // Clean up session
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testRedirectToFacebook(): void
    {
        // Setup
        $authUrl = 'https://facebook.com/oauth/authorize?some=parameters';
        $state = 'random_state_string';

        $this->facebookProvider->expects($this->once())
            ->method('getAuthorizationUrl')
            ->with(['scope' => ['email', 'public_profile']])
            ->willReturn($authUrl);

        $this->facebookProvider->expects($this->once())
            ->method('getState')
            ->willReturn($state);

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', $authUrl)
            ->willReturnSelf();

        $this->response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        // Execute
        $result = $this->authController->redirectToFacebook($this->request, $this->response);

        // Verify
        $this->assertSame($this->response, $result);
        $this->assertEquals($state, $_SESSION['oauth2state']);
    }

    public function testHandleFacebookCallbackWithError(): void
    {
        // Setup
        $queryParams = ['error' => 'access_denied'];

        $this->request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn($queryParams);

        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Facebook authentication failed: access_denied'));

        // Execute
        $result = $this->authController->handleFacebookCallback($this->request, $this->response);

        // Verify
        $this->assertSame($this->response, $result);
    }

    public function testHandleFacebookCallbackWithInvalidState(): void
    {
        // Setup
        $_SESSION['oauth2state'] = 'correct_state';
        $queryParams = ['state' => 'wrong_state', 'code' => 'authorization_code'];

        $this->request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn($queryParams);

        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Invalid state parameter'));

        // Execute
        $result = $this->authController->handleFacebookCallback($this->request, $this->response);

        // Verify
        $this->assertSame($this->response, $result);
        $this->assertArrayNotHasKey('oauth2state', $_SESSION);
    }

    public function testHandleFacebookCallbackSuccess(): void
    {
        // Setup
        $_SESSION['oauth2state'] = 'correct_state';
        $queryParams = ['state' => 'correct_state', 'code' => 'authorization_code'];
        $accessToken = $this->createMock(AccessToken::class);
        $user = new UserRepository();
        $user->setFirstName('John');
        $user->setLastName('Doe');
        $user->setEmail('john.doe@example.com');
        $user->setFacebookId('12345');

        $userData = [
            'id' => '12345',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'picture' => [
                'data' => [
                    'url' => 'https://example.com/profile.jpg'
                ]
            ]
        ];

        $resourceOwner = new \League\OAuth2\Client\Provider\FacebookUser($userData);

        $this->request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn($queryParams);

        $this->facebookProvider->expects($this->once())
            ->method('getAccessToken')
            ->with('authorization_code', ['code' => 'authorization_code'])
            ->willReturn($accessToken);

        $this->facebookProvider->expects($this->once())
            ->method('getResourceOwner')
            ->with($accessToken)
            ->willReturn($resourceOwner);

        $this->entityManager->expects($this->exactly(2))
            ->method('getRepository')
            ->with(UserRepository::class)
            ->willReturn($this->userRepository);

        $this->userRepository->expects($this->exactly(2))
            ->method('findOneBy')
            ->withConsecutive(
                [['facebookId' => '12345']],
                [['email' => 'john.doe@example.com']]
            )
            ->willReturnOnConsecutiveCalls(null, $user);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->request->expects($this->once())
            ->method('getAttribute')
            ->with(RouteContext::ROUTE_CONTEXT)
            ->willReturn($this->routeContext);

        $this->routeContext->expects($this->once())
            ->method('getRouteParser')
            ->willReturn($this->routeParser);

        $this->routeParser->expects($this->once())
            ->method('urlFor')
            ->with('home')
            ->willReturn('/');

        // Execute
        $result = $this->authController->handleFacebookCallback($this->request, $this->response);

        // Verify
        $this->assertSame($this->response, $result);
        $this->assertEquals($user->getId(), $_SESSION['user_id']);
        $this->assertEquals($user->getEmail(), $_SESSION['user_email']);
        $this->assertEquals($user->getFullName(), $_SESSION['user_name']);
    }

    public function testHandleFacebookCallbackNewUser(): void
    {
        // Setup
        $_SESSION['oauth2state'] = 'correct_state';
        $queryParams = ['state' => 'correct_state', 'code' => 'authorization_code'];
        $accessToken = $this->createMock(AccessToken::class);

        $userData = [
            'id' => '12345',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'picture' => [
                'data' => [
                    'url' => 'https://example.com/profile.jpg'
                ]
            ]
        ];

        $resourceOwner = new \League\OAuth2\Client\Provider\FacebookUser($userData);

        $this->request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn($queryParams);

        $this->facebookProvider->expects($this->once())
            ->method('getAccessToken')
            ->with('authorization_code', ['code' => 'authorization_code'])
            ->willReturn($accessToken);

        $this->facebookProvider->expects($this->once())
            ->method('getResourceOwner')
            ->with($accessToken)
            ->willReturn($resourceOwner);

        $this->entityManager->expects($this->exactly(2))
            ->method('getRepository')
            ->with(UserRepository::class)
            ->willReturn($this->userRepository);

        $this->userRepository->expects($this->exactly(2))
            ->method('findOneBy')
            ->withConsecutive(
                [['facebookId' => '12345']],
                [['email' => 'john.doe@example.com']]
            )
            ->willReturnOnConsecutiveCalls(null, null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($user) {
                return $user instanceof UserRepository
                    && $user->getFirstName() === 'John'
                    && $user->getLastName() === 'Doe'
                    && $user->getEmail() === 'john.doe@example.com'
                    && $user->getFacebookId() === '12345'
                    && $user->getAvatarUrl() === 'https://example.com/profile.jpg';
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->request->expects($this->once())
            ->method('getAttribute')
            ->with(RouteContext::ROUTE_CONTEXT)
            ->willReturn($this->routeContext);

        $this->routeContext->expects($this->once())
            ->method('getRouteParser')
            ->willReturn($this->routeParser);

        $this->routeParser->expects($this->once())
            ->method('urlFor')
            ->with('home')
            ->willReturn('/');

        // Execute
        $result = $this->authController->handleFacebookCallback($this->request, $this->response);

        // Verify
        $this->assertSame($this->response, $result);
        $this->assertArrayHasKey('user_id', $_SESSION);
        $this->assertArrayHasKey('user_email', $_SESSION);
        $this->assertArrayHasKey('user_name', $_SESSION);
    }

    public function testHandleFacebookCallbackException(): void
    {
        // Setup
        $_SESSION['oauth2state'] = 'correct_state';
        $queryParams = ['state' => 'correct_state', 'code' => 'authorization_code'];
        $exception = new \Exception('API Error');

        $this->request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn($queryParams);

        $this->facebookProvider->expects($this->once())
            ->method('getAccessToken')
            ->with('authorization_code', ['code' => 'authorization_code'])
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Facebook authentication error: API Error');

        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Authentication error: API Error'));

        // Execute
        $result = $this->authController->handleFacebookCallback($this->request, $this->response);

        // Verify
        $this->assertSame($this->response, $result);
    }
}