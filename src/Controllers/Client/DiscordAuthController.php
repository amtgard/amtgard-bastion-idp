<?php

declare(strict_types=1);


namespace Amtgard\IdP\Controllers\Client;

use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\Security\OAuth2StateManager;
use Amtgard\IdP\Utility\Security\OAuthSocialCallbackHandler;
use Amtgard\IdP\Utility\Security\OAuthSocialRedirectSessionStore;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Wohali\OAuth2\Client\Provider\Discord;

class DiscordAuthController extends BaseAuthController
{
    private UserRepository $users;
    private UserLoginRepository $logins;
    private Discord $discordProvider;

    public function __construct(
        UserRepository $users,
        UserLoginRepository $userLoginRepository,
        LoggerInterface $logger,
        AmtgardIdpJwt $amtgardIdpJwt,
        Discord $discordProvider
    ) {
        parent::__construct($logger, $amtgardIdpJwt);
        $this->users = $users;
        $this->logins = $userLoginRepository;
        $this->discordProvider = $discordProvider;
    }


    /**
     * Redirect to Discord for authentication.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function redirectToDiscord(Request $request, Response $response): Response
    {
        $authUrl = $this->discordProvider->getAuthorizationUrl([
            'scope' => ['identify', 'email']
        ]);

        OAuth2StateManager::store($this->discordProvider->getState());

        OAuthSocialRedirectSessionStore::storeFromQueryParams($request->getQueryParams());

        return $response
            ->withHeader('Location', $authUrl)
            ->withStatus(302);
    }

    /**
     * Handle the callback from Discord.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function handleDiscordCallback(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();

        return OAuthSocialCallbackHandler::builder()
            ->providerName('Discord')
            ->logger($this->logger)
            ->fetchToken(function (array $params) {
                return $this->discordProvider->getAccessToken('authorization_code', [
                    'code' => $params['code'],
                ]);
            })
            ->mapUserData(function ($token) {
                return $this->discordProvider->getResourceOwner($token)->toArray();
            })
            ->resolveUser(function (array $userData, AuthorizationFinalizeRedirect &$redirectPolicy) {
                $email = $userData['email'] ?? null;
                if (!$email) {
                    throw new \Exception('Email permission denied or not provided by Discord.');
                }

                return Optional::ofNullable($this->users->getUserByEmail($email))
                    ->orElseGet(function () use ($userData, &$redirectPolicy) {
                        $redirectPolicy = AuthorizationFinalizeRedirect::NewUserProfile;

                        return $this->users->createUserFromDiscordData($userData);
                    });
            })
            ->resolveLogin(function ($user, array $userData, $token) {
                return Optional::ofNullable($this->logins->getLoginByProviderId($userData['id']))
                    ->map(function ($login) use ($user, $token) {
                        $login->setUser($user);

                        return $this->logins->updateLoginTokens($login, fn ($t) => $t->getRefreshToken(), $token);
                    })
                    ->orElseGet(function () use ($user, $userData, $token) {
                        return $this->logins->createLoginFromDiscordData($user, $userData, $token);
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
