<?php

namespace Amtgard\IdP\Controllers\Client;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Controllers\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Repositories\UserRepository;
use League\OAuth2\Client\Provider\Google;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;

class GoogleAuthController extends BaseAuthController
{
    private UserRepository $users;
    private UserLoginRepository $logins;
    private Google $googleProvider;

    public function __construct(
        EntityManager   $entityManager,
        UserRepository  $users,
        UserLoginRepository $userLoginRepository,
        LoggerInterface $logger,
        Google          $googleProvider,
        AmtgardIdpJwt   $amtgardIdpJwt
    )
    {
        parent::__construct($logger, $amtgardIdpJwt);
        $this->users = $users;
        $this->logins = $userLoginRepository;
        $this->googleProvider = $googleProvider;
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
            $googleUser = $this->googleProvider->getResourceOwner($token);
            $userData = $googleUser->toArray();

            $this->logger->info('Google user data: ' . json_encode($userData));

            $user = Optional::ofNullable($this->users->getUserByEmail($userData['email']))
                ->orElseGet(function() use ($userData) {
                   return $this->users->createUserFromGoogleData($userData);
                });

            $login = Optional::ofNullable($this->logins->getLoginByGoogleId($userData['sub']))
                ->map(function($login) use ($user) {
                    $login->setUser($user);
                    return $login;
                })
                ->orElseGet(function() use ($user, $userData) {
                    return $this->logins->createLoginFromGoogleData($user, $userData);
                });

            return $this->finalizeAuthorization($login, $request, $response);
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