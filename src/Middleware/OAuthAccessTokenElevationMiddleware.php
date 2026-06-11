<?php

declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Jwt;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

/**
 * Auth for GET /resources/jwt — elevate an OAuth access token (or browser session)
 * to a signed authorization JWT. Does not accept RS256 authorization JWTs; those
 * belong on /resources/userinfo.
 */
class OAuthAccessTokenElevationMiddleware implements MiddlewareInterface
{
    public function __construct(
        EntityManager $entityManager,
        private LoggerInterface $logger,
        private AuthorizedClients $authorizedClients,
        private ResourceServer $resourceServer,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (Jwt::validateJwtRequest($request) !== null) {
            throw new HttpUnauthorizedException(
                $request,
                'Authorization JWTs are used with /resources/userinfo. Present an OAuth access token or session here.'
            );
        }

        $session = $request->getAttribute('session');

        if (Optional::ofNullable($session['client_id'] ?? null)
            ->map(fn($clientId) => !in_array($clientId, $this->authorizedClients->getClientIds(), true))
            ->orElse(false)) {
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        if (isset($session['user_id'])) {
            return $handler->handle($request);
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if (!preg_match('/Bearer\s+\S+/i', $authHeader)) {
            throw new HttpUnauthorizedException($request, 'OAuth access token or authenticated session required.');
        }

        try {
            $validated = $this->resourceServer->validateAuthenticatedRequest($request);
        } catch (OAuthServerException $e) {
            $this->logger->info('JWT elevation: invalid OAuth access token', ['msg' => $e->getMessage()]);
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        $_SESSION['user_id'] = $validated->getAttribute('oauth_user_id');
        $_SESSION['client_id'] = $validated->getAttribute('oauth_client_id');

        return $handler->handle($validated);
    }
}
