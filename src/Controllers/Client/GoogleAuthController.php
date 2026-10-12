<?php

declare(strict_types=1);


namespace Amtgard\IdP\Controllers\Client;

use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\Security\OAuth2StateManager;
use Amtgard\IdP\Utility\Security\OAuthSocialCallbackHandler;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\Security\OAuthSocialRedirectSessionStore;
use League\OAuth2\Client\Provider\Google;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class GoogleAuthController extends BaseAuthController
{
    private UserRepository $users;
    private UserLoginRepository $logins;
    private Google $googleProvider;

    public function __construct(
        UserRepository $users,
        UserLoginRepository $userLoginRepository,
        LoggerInterface $logger,
        IdpLogging $idpLogging,
        AmtgardIdpJwt $amtgardIdpJwt,
        Google $googleProvider
    ) {
        parent::__construct($logger, $idpLogging, $amtgardIdpJwt);
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
            'access_type' => 'offline',
            'prompt' => 'consent'
        ]);

        OAuth2StateManager::store($this->googleProvider->getState());

        OAuthSocialRedirectSessionStore::storeFromQueryParams($request->getQueryParams());

        $this->idpLogging->oauth()->info('oauth social redirect', ['provider' => 'Google']);

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

        return OAuthSocialCallbackHandler::builder()
            ->providerName('Google')
            ->logger($this->logger)
            ->idpLogging($this->idpLogging)
            ->fetchToken(function (array $params) {
                return $this->googleProvider->getAccessToken('authorization_code', [
                    'code' => $params['code'],
                ]);
            })
            ->mapUserData(function ($token) {
                return $this->googleProvider->getResourceOwner($token)->toArray();
            })
            ->resolveUser(function (array $userData, AuthorizationFinalizeRedirect &$redirectPolicy) {
                return Optional::ofNullable($this->logins->getLoginByProviderId($userData['sub']))
                    ->map(fn ($login) => $this->userForProviderLogin($this->users, $login))
                    ->orElseGet(function () use ($userData, &$redirectPolicy) {
                        return Optional::ofNullable($this->users->getUserByEmail($userData['email']))
                            ->orElseGet(function () use ($userData, &$redirectPolicy) {
                                $redirectPolicy = AuthorizationFinalizeRedirect::NewUserProfile;
                                $email = (string) ($userData['email'] ?? '');
                                $this->idpLogging->oauth()->info('oauth social user provisioned', [
                                    'provider' => 'Google',
                                    'provision' => 'new_user',
                                    'email_sha256' => $email !== '' ? hash('sha256', $email) : null,
                                ]);

                                return $this->users->createUserFromGoogleData($userData);
                            });
                    });
            })
            ->resolveLogin(function ($user, array $userData, $token) {
                return Optional::ofNullable($this->logins->getLoginByProviderId($userData['sub']))
                    ->map(function ($login) use ($user, $token) {
                        $login->setUser($user);

                        return $this->logins->updateLoginTokens($login, fn ($t) => $t->getRefreshToken(), $token);
                    })
                    ->orElseGet(function () use ($user, $userData, $token) {
                        $this->idpLogging->oauth()->info('oauth social login provisioned', [
                            'provider' => 'Google',
                            'provision' => 'new_login',
                        ]);

                        return $this->logins->createLoginFromGoogleData($user, $userData, $token);
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
