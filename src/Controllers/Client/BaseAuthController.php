<?php

declare(strict_types=1);


namespace Amtgard\IdP\Controllers\Client;

use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Entities\UserLoginEntity;
use Amtgard\IdP\Utility\Constants;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\Security\RedirectValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;

class BaseAuthController
{
    protected LoggerInterface $logger;
    protected AmtgardIdpJwt $amtgardIdpJwt;

    public function __construct(LoggerInterface $logger, AmtgardIdpJwt $amtgardIdpJwt)
    {
        $this->logger = $logger;
        $this->amtgardIdpJwt = $amtgardIdpJwt;
    }

    protected function finalizeAuthorization(
        UserLoginEntity $login,
        ServerRequestInterface $request,
        ResponseInterface $response,
        AuthorizationFinalizeRedirect $redirectPolicy = AuthorizationFinalizeRedirect::ReturningUserWithStoredRedirect,
    ): ResponseInterface
    {
        $this->logger->info('User authenticated; setting session', [
            'user_id' => $login->user->getUserId(),
        ]);
        $_SESSION['client_id'] = Constants::$AMTGARD_IDP_CLIENT_ID;
        $_SESSION['user_id'] = $login->user->getUserId();
        $_SESSION['user_email'] = $login->user->getEmail();
        $_SESSION['user_name'] = $login->user->getFullName();
        $_SESSION['avatar_url'] = $login->getAvatarUrl();
        if ($login->getId() !== null) {
            LoginSession::setLoginId($login->getId());
        }

        // Redirect to home page
        $routeContext = RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        $this->logger->info('Building authorization JWT', [
            'user_id' => $login->user->getUserId(),
        ]);
        $jwt = $this->amtgardIdpJwt->buildAuthorizationJwt($login->user);

        $profileUrl = $routeParser->urlFor('resources.profile');
        $storedRedirect = RedirectValidator::sanitizeOrNull($_SESSION['redirect'] ?? null);

        $finalizeUrl = match ($redirectPolicy) {
            AuthorizationFinalizeRedirect::NewUserProfile => $profileUrl,
            AuthorizationFinalizeRedirect::ReturningUserWithStoredRedirect => $storedRedirect !== null
                ? ($storedRedirect . "?jwt=$jwt")
                : $profileUrl,
        };

        $this->logger->info('Redirecting user after authorization', [
            'user_id' => $login->user->getUserId(),
            'redirect_policy' => $redirectPolicy->name,
        ]);
        return $response
            ->withHeader('Location', $finalizeUrl)
            ->withStatus(302);
    }

}