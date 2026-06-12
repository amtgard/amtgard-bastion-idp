<?php

namespace Amtgard\IdP\Controllers\Client;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\Security\OAuth2StateManager;
use Amtgard\IdP\Utility\Security\OAuthCallbackValidator;
use Amtgard\IdP\Utility\Security\RedirectValidator;
use Amtgard\IdP\Utility\Security\ScriptAlertResponse;
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
        EntityManager $entityManager,
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

        $queryParams = $request->getQueryParams();
        $_SESSION['redirect'] = RedirectValidator::sanitizeOrNull($queryParams['redirect'] ?? null);
        $_SESSION['jwtpublickey'] = $queryParams['jwtpublickey'] ?? null;

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

        $validationResult = OAuthCallbackValidator::validate($callbackParams, 'Apple');

        if ($validationResult !== null) {
            $response->getBody()->write($validationResult);
            return $response;
        }

        try {
            $this->syncSuperglobalsForAppleProvider($callbackParams);

            $token = $this->appleProvider->getAccessToken('authorization_code', [
                'code' => $callbackParams['code'],
            ]);

            $appleUser = $this->appleProvider->getResourceOwner($token);
            $userData = $appleUser->toArray();
            $providerId = (string) $appleUser->getId();
            $email = $appleUser->getEmail();

            $this->logger->debug('Apple user data: ' . json_encode($userData));

            $isNewUser = false;
            $existingLogin = $this->logins->getLoginByProviderId($providerId);

            $user = Optional::ofNullable($existingLogin)
                ->map(fn ($login) => $login->user)
                ->orElseGet(function () use ($email, $appleUser, &$isNewUser) {
                    if ($email === null || $email === '') {
                        throw new \Exception(
                            'Apple did not provide an email address. If you have signed in before, use the same Apple ID. Otherwise, revoke Amtgard access in Apple ID settings and try again.'
                        );
                    }

                    return Optional::ofNullable($this->users->getUserByEmail($email))
                        ->orElseGet(function () use ($email, $appleUser, &$isNewUser) {
                            $isNewUser = true;
                            return $this->users->createUserFromAppleData([
                                'email' => $email,
                                'given_name' => $appleUser->getFirstName() ?? '',
                                'family_name' => $appleUser->getLastName() ?? '',
                            ]);
                        });
                });

            $login = Optional::ofNullable($existingLogin)
                ->map(function ($login) use ($user, $token) {
                    $login->setUser($user);
                    return $this->logins->updateLoginTokens($login, fn($t) => $t->getRefreshToken(), $token);
                })
                ->orElseGet(function () use ($user, $userData, $providerId, $token) {
                    $userData['sub'] = $providerId;
                    return $this->logins->createLoginFromAppleData($user, $userData, $token);
                });

            return $this->finalizeAuthorization($login, $request, $response, $isNewUser);
        } catch (\Exception $e) {
            $this->logger->error('Apple authentication error: ' . $e->getTraceAsString());

            $response->getBody()->write(
                ScriptAlertResponse::alertAndRedirect($e->getMessage(), '/auth/login?policy')
            );
            return $response;
        }
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
