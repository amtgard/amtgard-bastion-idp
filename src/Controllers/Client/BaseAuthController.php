<?php

declare(strict_types=1);


namespace Amtgard\IdP\Controllers\Client;

use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserLoginEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\Constants;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\Security\RedirectValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;

class BaseAuthController
{
    protected LoggerInterface $logger;

    protected IdpLogging $idpLogging;

    protected AmtgardIdpJwt $amtgardIdpJwt;

    public function __construct(LoggerInterface $logger, IdpLogging $idpLogging, AmtgardIdpJwt $amtgardIdpJwt)
    {
        $this->logger = $logger;
        $this->idpLogging = $idpLogging;
        $this->amtgardIdpJwt = $amtgardIdpJwt;
    }

    protected function userForProviderLogin(UserRepository $users, UserLoginEntity $login): ?UserEntity
    {
        $userId = $login->getLinkedUserId();
        if ($userId === null) {
            return null;
        }

        return $users->findUserById($userId);
    }

    protected function finalizeAuthorization(
        UserLoginEntity $login,
        ServerRequestInterface $request,
        ResponseInterface $response,
        AuthorizationFinalizeRedirect $redirectPolicy = AuthorizationFinalizeRedirect::ReturningUserWithStoredRedirect,
    ): ResponseInterface
    {
        $userUuid = $login->user->getUserId();
        $loginId = $login->getId();
        $sessionContext = [
            'user_uuid' => $userUuid,
            'client_id' => Constants::$AMTGARD_IDP_CLIENT_ID,
            'login_id' => $loginId,
        ];

        $this->idpLogging->security()->info('User authenticated; setting session', $sessionContext);
        $_SESSION['client_id'] = Constants::$AMTGARD_IDP_CLIENT_ID;
        $_SESSION['user_id'] = $userUuid;
        $_SESSION['user_email'] = $login->user->getEmail();
        $_SESSION['user_name'] = $login->user->getFullName();
        $_SESSION['avatar_url'] = $login->getAvatarUrl();
        if ($loginId !== null) {
            LoginSession::setLoginId($loginId);
        }

        $routeContext = RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        $this->idpLogging->security()->info('Building authorization JWT', $sessionContext);
        $jwt = $this->amtgardIdpJwt->buildAuthorizationJwt($login->user);

        $profileUrl = $routeParser->urlFor('resources.profile');
        $storedRedirect = RedirectValidator::sanitizeOrNull($_SESSION['redirect'] ?? null);

        $finalizeUrl = match ($redirectPolicy) {
            AuthorizationFinalizeRedirect::NewUserProfile => $profileUrl,
            AuthorizationFinalizeRedirect::ReturningUserWithStoredRedirect => $storedRedirect !== null
                ? ($storedRedirect . "?jwt=$jwt")
                : $profileUrl,
        };

        $this->idpLogging->security()->info('Redirecting user after authorization', [
            ...$sessionContext,
            'redirect_policy' => $redirectPolicy->name,
        ]);

        return $response
            ->withHeader('Location', $finalizeUrl)
            ->withStatus(302);
    }

}
