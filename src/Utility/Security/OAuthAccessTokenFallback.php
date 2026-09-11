<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

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
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        $userId = (string) $validated->getAttribute('oauth_user_id');
        $clientId = (string) $validated->getAttribute('oauth_client_id');

        return $proceed($userId, $clientId, $validated, $handler);
    }
}
