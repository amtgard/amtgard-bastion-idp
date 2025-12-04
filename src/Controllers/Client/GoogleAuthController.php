<?php

namespace Amtgard\IdP\Controllers\Client;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\AuthClient\Repositories\UserRepository;
use Amtgard\IdP\Controllers\AmtgardIdpJwt;
use League\OAuth2\Client\Provider\Google;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;

class GoogleAuthController
{
    private UserRepository $users;
    private LoggerInterface $logger;
    private Google $googleProvider;
    private AmtgardIdpJwt $amtgardIdpJwt;

    public function __construct(
        EntityManager   $entityManager,
        UserRepository  $users,
        LoggerInterface $logger,
        Google          $googleProvider,
        AmtgardIdpJwt   $amtgardIdpJwt
    )
    {
        $this->users = $users;
        $this->logger = $logger;
        $this->googleProvider = $googleProvider;
        $this->amtgardIdpJwt = $amtgardIdpJwt;
    }


    /**
     * Redirect to Google for authentication.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function redirectToGoogle(Request $request, Response $response): Response
    {
        $authUrl = $this->googleProvider->getAuthorizationUrl([
            'scope' => ['email', 'profile'],
        ]);

        // Store state in session for CSRF protection
        $_SESSION['oauth2state'] = $this->googleProvider->getState();

        $_SESSION['redirect'] = $request->getQueryParams()['redirect'];
        $_SESSION['jwtpublickey'] = $request->getQueryParams()['jwtpublickey'];

        return $response
            ->withHeader('Location', $authUrl)
            ->withStatus(302);
    }

    /**
     * Handle the callback from Google.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function handleGoogleCallback(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();

        // Check for errors
        if (isset($queryParams['error'])) {
            $response->getBody()->write('
                <script>
                    alert("Google authentication failed: ' . htmlspecialchars($queryParams['error']) . '");
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
            $token = $this->googleProvider->getAccessToken('authorization_code', [
                'code' => $queryParams['code']
            ]);

            // Get user details
            $user = $this->googleProvider->getResourceOwner($token);
            $userData = $user->toArray();

            $this->logger->info('Google user data: ' . json_encode($userData));

            $user = Optional::ofNullable($this->users->getUserByGoogleId($userData['sub']))
                ->orElseGet(function() use ($userData) {
                    return Optional::ofNullable($this->users->getUserByEmail($userData['email']))
                        ->map(function($user) use ($userData) {
                            $user->setGoogleId($userData['id']);
                            $user->setAvatarUrl($userData['picture']);
                        })
                        ->orElseGet(function() use ($userData) {
                            $user = $this->users->createUserFromGoogleData($userData);
                            return $this->users->getUserByGoogleId($user->getGoogleId());
                        });
                });

            // Set session
            $_SESSION['user_id'] = $user->getGoogleId();
            $_SESSION['user_email'] = $user->getEmail();
            $_SESSION['user_name'] = $user->getFirstName() . ' ' . $user->getLastName();

            // Redirect to home page
            $routeContext = RouteContext::fromRequest($request);
            $routeParser = $routeContext->getRouteParser();

            $jwt = $this->amtgardIdpJwt->buildSingleUseJwt($user, $_SESSION['jwtpublickey']);

            $finalizeUrl = empty($_SESSION['redirect']) ? $routeParser->urlFor('home') : ($_SESSION['redirect'] . "?jwt=$jwt");

            return $response
                ->withHeader('Location', $finalizeUrl)
                ->withStatus(302);

        } catch (\Exception $e) {
            $this->logger->error('Google authentication error: ' . $e->getTraceAsString());

            $response->getBody()->write('
                <script>
                    alert("Authentication error: ' . htmlspecialchars($e->getMessage()) . '");
                    window.location.href = "/auth/login?policy";
                </script>
            ');
            return $response;
        }
    }


}