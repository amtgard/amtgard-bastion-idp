<?php

namespace Amtgard\IdP\Controllers\Client;

use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\UserRepository;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;
use Twig\Environment as TwigEnvironment;

class FacebookAuthController
{
    private UserRepository $users;
    private EntityMapper $userMapper;
    private LoggerInterface $logger;
    private Google $googleProvider;
    private Facebook $facebookProvider;
    private TwigEnvironment $twig;

    public function __construct(
        EntityManager   $entityManager,
        UserRepository  $users,
        LoggerInterface $logger,
        Facebook        $facebookProvider
    )
    {
        $this->users = $users;
        $this->logger = $logger;
        $this->facebookProvider = $facebookProvider;
    }


    /**
     * Redirect to Facebook for authentication.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function redirectToFacebook(Request $request, Response $response): Response
    {
        $authUrl = $this->facebookProvider->getAuthorizationUrl([
            'scope' => ['email', 'public_profile'],
        ]);

        // Store state in session for CSRF protection
        $_SESSION['oauth2state'] = $this->facebookProvider->getState();

        return $response
            ->withHeader('Location', $authUrl)
            ->withStatus(302);
    }

    /**
     * Handle the callback from Facebook.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function handleFacebookCallback(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();

        // Check for errors
        if (isset($queryParams['error'])) {
            $response->getBody()->write('
                <script>
                    alert("Facebook authentication failed: ' . htmlspecialchars($queryParams['error']) . '");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }

        // Validate state to prevent CSRF attacks
        if (empty($queryParams['state']) || ($queryParams['state'] !== $_SESSION['oauth2state'])) {
            unset($_SESSION['oauth2state']);

            $response->getBody()->write('
                <script>
                    alert("Invalid state parameter");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }

        try {
            // Get access token
            $token = $this->facebookProvider->getAccessToken('authorization_code', [
                'code' => $queryParams['code']
            ]);

            // Get user details
            $user = $this->facebookProvider->getResourceOwner($token);
            $userData = $user->toArray();

            // Check if user exists
            $userRepository = $this->entityManager->getRepository(UserRepository::class);
            $existingUser = $userRepository->findOneBy(['facebookId' => $userData['id']]);

            if ($existingUser === null) {
                // Check if email exists
                $existingUser = $userRepository->findOneBy(['email' => $userData['email']]);

                if ($existingUser === null) {
                    // Create new user
                    $existingUser = new UserRepository();
                    $existingUser->setFirstName($userData['first_name']);
                    $existingUser->setLastName($userData['last_name']);
                    $existingUser->setEmail($userData['email']);
                    $existingUser->setFacebookId($userData['id']);

                    // Get profile picture if available
                    if (isset($userData['picture']['data']['url'])) {
                        $existingUser->setAvatarUrl($userData['picture']['data']['url']);
                    }

                    $this->entityManager->persist($existingUser);
                } else {
                    // Update existing user with Facebook ID
                    $existingUser->setFacebookId($userData['id']);

                    // Update avatar if available and user doesn't have one
                    if (isset($userData['picture']['data']['url']) && $existingUser->getAvatarUrl() === null) {
                        $existingUser->setAvatarUrl($userData['picture']['data']['url']);
                    }
                }

                $this->entityManager->flush();
            }

            // Set session
            $_SESSION['user_id'] = $existingUser->getId();
            $_SESSION['user_email'] = $existingUser->getEmail();
            $_SESSION['user_name'] = $existingUser->getFullName();

            // Redirect to home page
            $routeContext = RouteContext::fromRequest($request);
            $routeParser = $routeContext->getRouteParser();

            return $response
                ->withHeader('Location', $routeParser->urlFor('home'))
                ->withStatus(302);

        } catch (\Exception $e) {
            $this->logger->error('Facebook authentication error: ' . $e->getMessage());

            $response->getBody()->write('
                <script>
                    alert("Authentication error: ' . htmlspecialchars($e->getMessage()) . '");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }
    }

}