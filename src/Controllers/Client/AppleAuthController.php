<?php

declare(strict_types=1);


namespace Amtgard\IdP\Controllers\Client;

use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\Security\OAuth2StateManager;
use Amtgard\IdP\Utility\Security\OAuthSocialCallbackHandler;
use Amtgard\IdP\Utility\Security\OAuthSocialRedirectSessionStore;
use League\OAuth2\Client\Provider\Apple;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class AppleAuthController extends BaseAuthController
{
    private UserRepository $users;
    private UserLoginRepository $logins;
    private Apple $appleProvider;

    public function __construct(
        UserRepository $users,
        UserLoginRepository $userLoginRepository,
        LoggerInterface $logger,
        AmtgardIdpJwt $amtgardIdpJwt,
        Apple $appleProvider
    ) {
        parent::__construct($logger, $amtgardIdpJwt);
        $this->users = $users;
        $this->logins = $userLoginRepository;
        $this->appleProvider = $appleProvider;
    }

    /**
     * Redirect to Apple for authentication.
     */
    public function redirectToApple(Request $request, Response $response): Response
    {
        $authUrl = $this->appleProvider->getAuthorizationUrl([
            'scope' => ['name', 'email'],
        ]);

        OAuth2StateManager::store($this->appleProvider->getState());

        OAuthSocialRedirectSessionStore::storeFromQueryParams($request->getQueryParams());

        return $response
            ->withHeader('Location', $authUrl)
            ->withStatus(302);
    }

    /**
     * Handle the callback from Apple (form_post).
     */
    public function handleAppleCallback(Request $request, Response $response): Response
    {
        $callbackParams = $this->callbackParams($request);
        $existingLogin = null;
        $providerId = null;
        $appleUser = null;

        return OAuthSocialCallbackHandler::builder()
            ->providerName('Apple')
            ->logger($this->logger)
            ->fetchToken(function (array $params) use (&$existingLogin, &$providerId, &$appleUser) {
                $this->syncSuperglobalsForAppleProvider($params);

                $token = $this->appleProvider->getAccessToken('authorization_code', [
                    'code' => $params['code'],
                ]);

                $appleUser = $this->appleProvider->getResourceOwner($token);
                $providerId = (string) $appleUser->getId();
                $existingLogin = $this->logins->getLoginByProviderId($providerId);

                return $token;
            })
            ->mapUserData(function ($token) use (&$appleUser) {
                return $appleUser->toArray();
            })
            ->resolveUser(function (array $userData, AuthorizationFinalizeRedirect &$redirectPolicy) use (
                &$existingLogin,
                &$appleUser,
            ) {
                return Optional::ofNullable($existingLogin)
                    ->map(fn ($login) => $login->user)
                    ->orElseGet(function () use (&$appleUser, &$redirectPolicy) {
                        $email = $appleUser->getEmail();

                        if ($email === null || $email === '') {
                            throw new \Exception(
                                'Apple did not provide an email address. If you have signed in before, use the same Apple ID. Otherwise, revoke Amtgard access in Apple ID settings and try again.'
                            );
                        }

                        return Optional::ofNullable($this->users->getUserByEmail($email))
                            ->orElseGet(function () use ($email, &$appleUser, &$redirectPolicy) {
                                $redirectPolicy = AuthorizationFinalizeRedirect::NewUserProfile;

                                return $this->users->createUserFromAppleData([
                                    'email' => $email,
                                    'given_name' => $appleUser->getFirstName() ?? '',
                                    'family_name' => $appleUser->getLastName() ?? '',
                                ]);
                            });
                    });
            })
            ->resolveLogin(function ($user, array $userData, $token) use (&$existingLogin, &$providerId) {
                return Optional::ofNullable($existingLogin)
                    ->map(function ($login) use ($user, $token) {
                        $login->setUser($user);

                        return $this->logins->updateLoginTokens($login, fn ($t) => $t->getRefreshToken(), $token);
                    })
                    ->orElseGet(function () use ($user, $userData, $providerId, $token) {
                        $userData['sub'] = $providerId;

                        return $this->logins->createLoginFromAppleData($user, $userData, $token);
                    });
            })
            ->build()
            ->handle(
                $callbackParams,
                $response,
                fn ($login, $redirectPolicy) => $this->finalizeAuthorization(
                    $login,
                    $request,
                    $response,
                    $redirectPolicy
                ),
            );
    }

    /**
     * Apple uses form_post; callback fields arrive in the request body.
     *
     * @return array<string, mixed>
     */
    private function callbackParams(Request $request): array
    {
        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            return $request->getQueryParams();
        }

        return $parsedBody;
    }

    /**
     * The Apple OAuth provider reads name details from $_POST during getResourceOwner().
     *
     * @param array<string, mixed> $callbackParams
     */
    private function syncSuperglobalsForAppleProvider(array $callbackParams): void
    {
        $_POST = $callbackParams;
    }
}
