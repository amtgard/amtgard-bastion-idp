<?php
declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Utility;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteContext;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;

class LocalIdpAuthMiddleware implements MiddlewareInterface
{
    protected ResourceServer $resourceServer;
    protected LoggerInterface $logger;
    protected AuthorizedClients $authorizedClients;

    public function __construct(
        EntityManager $em,
        LoggerInterface $logger,
        AuthorizedClients $authorizedClients,
        ResourceServer $resourceServer)
    {
        $this->logger = $logger;
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
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $session = $request->getAttribute('session');

        if (Optional::ofNullable($session['client_id'])->map(fn($clientId) => !in_array($clientId, $this->authorizedClients->getClientIds()))->orElse(false)) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        // Check if user is logged in via session
        if (isset($session['user_id'])) {
            return $handler->handle($request);
        }

        // If not in session, check for Bearer Token
        try {
            $request = $this->resourceServer->validateAuthenticatedRequest($request);
            // If we get here, the token is valid. Request now has oauth_access_token_id, oauth_client_id, oauth_user_id, oauth_scopes attributes
            $_SESSION['user_id'] = $request->getAttribute('oauth_user_id');
            return $handler->handle($request);
        } catch (OAuthServerException $exception) {
            // Token invalid or missing, proceed to redirect logic
        }

        // Neither session nor valid token found, redirect to login
        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        // Get the route parser
        $routeContext = RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        // Redirect to login page
        return $response
            ->withHeader('Location', $routeParser->urlFor('auth.login'))
            ->withStatus(302);
    }
}