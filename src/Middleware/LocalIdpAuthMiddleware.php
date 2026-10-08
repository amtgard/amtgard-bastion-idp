<?php
declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Log\IdpLogging;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteContext;

class LocalIdpAuthMiddleware implements MiddlewareInterface
{
    protected ResourceServer $resourceServer;
    protected IdpLogging $logging;
    protected AuthorizedClients $authorizedClients;

    public function __construct(
        EntityManager $em,
        IdpLogging $logging,
        AuthorizedClients $authorizedClients,
        ResourceServer $resourceServer)
    {
        $this->logging = $logging;
        $this->resourceServer = $resourceServer;
        $this->authorizedClients = $authorizedClients;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $this->logging->security()->error('local idp auth rejected', [
                'failure_reason' => 'bearer_not_allowed',
            ]);
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $session = $request->getAttribute('session');

        if (Optional::ofNullable($session['client_id'] ?? null)->map(fn($clientId) => !in_array($clientId, $this->authorizedClients->getClientIds()))->orElse(false)) {
            $this->logging->security()->error('local idp auth rejected', [
                'client_id' => $session['client_id'],
                'failure_reason' => 'client_not_authorized',
            ]);
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        if (isset($session['user_id'])) {
            $this->logging->security()->debug('local idp session auth proceed', [
                'user_uuid' => $session['user_id'],
            ]);

            return $handler->handle($request);
        }

        try {
            $request = $this->resourceServer->validateAuthenticatedRequest($request);
            $this->logging->oauth()->debug('local idp oauth resource auth proceed', [
                'oauth_user_id' => $request->getAttribute('oauth_user_id'),
                'oauth_client_id' => $request->getAttribute('oauth_client_id'),
            ]);
            $_SESSION['user_id'] = $request->getAttribute('oauth_user_id');
            return $handler->handle($request);
        } catch (OAuthServerException $exception) {
            $payload = $exception->getPayload();
            $this->logging->oauth()->warning('local idp oauth token validation failed', [
                'exception_class' => $exception::class,
                'oauth_hint' => $payload['hint'] ?? null,
            ]);
        }

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $routeContext = RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        $this->logging->app()->notice('local idp redirect to login', [
            'path' => $request->getUri()->getPath(),
            'http_status' => 302,
        ]);

        return $response
            ->withHeader('Location', $routeParser->urlFor('auth.login'))
            ->withStatus(302);
    }
}
