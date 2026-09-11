<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server;

use Amtgard\IdP\Controllers\Server\OAuth\OAuthApproveAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthAuthorizeAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthSessionAuthRequestStore;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthTokenAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OAuth2ServerController
{
    public function __construct(
        private OAuthTokenAction $tokenAction,
        private OAuthApproveAction $approveAction,
        private OAuthAuthorizeAction $authorizeAction,
        private OAuthSessionAuthRequestStore $authRequestStore,
    ) {
    }

    public function token(Request $request, Response $response): Response
    {
        return $this->tokenAction->handle($request, $response);
    }

    public function approve(Request $request, Response $response): Response
    {
        return $this->approveAction->handle($request, $response);
    }

    public function clearAuthentication(Request $request, Response $response): Response
    {
        $this->authRequestStore->clearSessionUserId();

        return $response;
    }

    public function clearAuthorizationAndApproval(Request $request, Response $response): Response
    {
        $this->authRequestStore->clearAuthorizationState();

        return $response;
    }

    public function authorize(Request $request, Response $response): Response
    {
        return $this->authorizeAction->handle($request, $response);
    }

    public function buildPostAuthenticationRedirectUrl(): string
    {
        return $this->authorizeAction->buildPostAuthenticationRedirectUrl();
    }

    public function authorizePost(Request $request, Response $response): Response
    {
        return $response;
    }
}
