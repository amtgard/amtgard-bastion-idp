<?php

declare(strict_types=1);


namespace Amtgard\IdP\Controllers\Client;

use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\Security\OAuth2StateManager;
use Amtgard\IdP\Utility\Security\OAuthSocialCallbackHandler;
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

        OAuth2StateManager::store($this->facebookProvider->getState());

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

        return OAuthSocialCallbackHandler::builder()
            ->providerName('Facebook')
            ->logger($this->logger)
            ->errorRedirectPath('/auth/login')
            ->fetchToken(function (array $params) {
                $token = $this->facebookProvider->getAccessToken('authorization_code', [
                    'code' => $params['code'],
                ]);

                return $this->facebookProvider->getLongLivedAccessToken($token->getToken());
            })
            ->mapUserData(function ($token) {
                return $this->facebookProvider->getResourceOwner($token)->toArray();
            })
            ->resolveUser(function (array $userData, AuthorizationFinalizeRedirect &$redirectPolicy) {
                return Optional::ofNullable($this->logins->getLoginByProviderId($userData['id']))
                    ->map(fn ($login) => $login->user)
                    ->orElseGet(function () use ($userData, &$redirectPolicy) {
                        return Optional::ofNullable($this->users->getUserByEmail($userData['email']))
                            ->orElseGet(function () use ($userData, &$redirectPolicy) {
                                $redirectPolicy = AuthorizationFinalizeRedirect::NewUserProfile;

                                return $this->users->createUserFromFacebookData($userData);
                            });
                    });
            })
            ->resolveLogin(function ($user, array $userData, $token) {
                return Optional::ofNullable($this->logins->getLoginByProviderId($userData['id']))
                    ->map(function ($login) use ($user, $token) {
                        $login->setUser($user);

                        return $this->logins->updateLoginTokens($login, fn ($t) => $t->getToken(), $token);
                    })
                    ->orElseGet(function () use ($user, $userData, $token) {
                        return $this->logins->createLoginFromFacebookData($user, $userData, $token);
                    });
            })
            ->build()
            ->handle(
                $queryParams,
                $response,
                fn ($login, $redirectPolicy) => $this->finalizeAuthorization(
                    $login,
                    $request,
                    $response,
                    $redirectPolicy
                ),
            );
    }

}
