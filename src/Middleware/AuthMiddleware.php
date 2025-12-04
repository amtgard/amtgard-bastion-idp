<?php
declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteContext;

class AuthMiddleware implements MiddlewareInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $session = $request->getAttribute('session');
        
        // Check if user is logged in
        if (!isset($session['user_id'])) {
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
        
        return $handler->handle($request);
    }
}