<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Amtgard\IdP\Utility\Log\IdpLogging;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpUnauthorizedException;

final class OAuthAccessTokenFallback
{
    public function __construct(
        private ResourceServer $resourceServer,
        private IdpLogging $idpLogging,
    ) {
    }

    /**
     * @param callable(string, string, ServerRequestInterface, RequestHandlerInterface): ResponseInterface $proceed
     */
    public function authenticate(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        callable $proceed,
    ): ResponseInterface {
        try {
            $validated = $this->resourceServer->validateAuthenticatedRequest($request);
        } catch (OAuthServerException) {
            $this->idpLogging->security()->error('oauth access token fallback rejected', [
                'failure_reason' => 'invalid_access_token',
            ]);
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        $userId = (string) $validated->getAttribute('oauth_user_id');
        $clientId = (string) $validated->getAttribute('oauth_client_id');

        $this->idpLogging->security()->debug('oauth access token fallback accepted', [
            'user_uuid' => $userId,
            'client_id' => $clientId,
        ]);

        return $proceed($userId, $clientId, $validated, $handler);
    }
}
