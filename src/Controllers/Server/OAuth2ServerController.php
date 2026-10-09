<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server;

use Amtgard\IdP\Controllers\Server\OAuth\OAuthApproveAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthAuthorizeAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthSessionAuthRequestStore;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthTokenAction;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OAuth2ServerController
{
    public function __construct(
        private OAuthTokenAction $tokenAction,
        private OAuthApproveAction $approveAction,
        private OAuthAuthorizeAction $authorizeAction,
        private OAuthSessionAuthRequestStore $authRequestStore,
        private IdpLogging $idpLogging,
    ) {
    }

    public function token(Request $request, Response $response): Response
    {
        $this->idpLogging->oauth()->debug('oauth server dispatch', [
            'oauth_step' => 'token',
        ]);

        return $this->tokenAction->handle($request, $response);
    }

    public function approve(Request $request, Response $response): Response
    {
        $this->idpLogging->oauth()->debug('oauth server dispatch', [
            'method' => $request->getMethod(),
        ]);

        return $this->approveAction->handle($request, $response);
    }

    public function clearAuthentication(Request $request, Response $response): Response
    {
        $this->authRequestStore->clearSessionUserId();
        $this->idpLogging->oauth()->notice('oauth session cleared', [
            'oauth_step' => 'clear_authn',
        ]);

        return $response;
    }

    public function clearAuthorizationAndApproval(Request $request, Response $response): Response
    {
        $this->authRequestStore->clearAuthorizationState();
        $this->idpLogging->oauth()->notice('oauth authorization state cleared', [
            'oauth_step' => 'clear_authz',
        ]);

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
        $this->idpLogging->oauth()->notice('oauth authorize post noop', [
            'oauth_step' => 'authorize_post_noop',
        ]);

        return $response;
    }
}
