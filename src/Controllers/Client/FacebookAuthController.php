<?php

namespace Amtgard\IdP\Controllers\Client;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use League\OAuth2\Client\Provider\Facebook;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class FacebookAuthController extends BaseAuthController
{
    private UserRepository $users;
    private UserLoginRepository $logins;
    private Facebook $facebookProvider;

    public function __construct(
        EntityManager $entityManager,
        UserRepository $users,
        UserLoginRepository $userLoginRepository,
        LoggerInterface $logger,
        AmtgardIdpJwt $amtgardIdpJwt,
        Facebook $facebookProvider
    ) {
        parent::__construct($logger, $amtgardIdpJwt);
        $this->users = $users;
        $this->logins = $userLoginRepository;
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

            // Exchange for long-lived token
            $token = $this->facebookProvider->getLongLivedAccessToken($token->getToken());

            // Get user details
            $user = $this->facebookProvider->getResourceOwner($token);
            $userData = $user->toArray();

            $this->logger->debug('Facebook user data: ' . json_encode($userData));

            $user = Optional::ofNullable($this->users->getUserByEmail($userData['email']))
                ->orElseGet(function () use ($userData) {
                    return $this->users->createUserFromFacebookData($userData);
                });

            $login = Optional::ofNullable($this->logins->getLoginByProviderId($userData['id']))
                ->map(function ($login) use ($user, $token) {
                    $login->setUser($user);
                    return $this->logins->updateLoginTokens($login, fn($t) => $t->getToken(), $token);
                })
                ->orElseGet(function () use ($user, $userData, $token) {
                    return $this->logins->createLoginFromFacebookData($user, $userData, $token);
                });

            return $this->finalizeAuthorization($login, $request, $response);
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