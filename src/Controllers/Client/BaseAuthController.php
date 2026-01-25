<?php

namespace Amtgard\IdP\Controllers\Client;

use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Entities\UserLoginEntity;
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

    protected function finalizeAuthorization(UserLoginEntity $login, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->logger->info("User is authenticated; setting session for " . $login->user->getEmail());
        $_SESSION['user_id'] = $login->user->getUserId();
        $_SESSION['user_email'] = $login->user->getEmail();
        $_SESSION['user_name'] = $login->user->getFullName();
        $_SESSION['avatar_url'] = $login->getAvatarUrl();

        // Redirect to home page
        $routeContext = RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        $this->logger->info("Building JWT for " . $login->user->getEmail());
        $jwt = $this->amtgardIdpJwt->buildSingleUseJwt($login->user, $_SESSION['jwtpublickey']);

        $finalizeUrl = empty($_SESSION['redirect']) ? $routeParser->urlFor('resources.profile') : ($_SESSION['redirect'] . "?jwt=$jwt");

        $this->logger->info("Redirecting user for " . $login->user->getEmail());
        return $response
            ->withHeader('Location', $finalizeUrl)
            ->withStatus(302);
    }

}