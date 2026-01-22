<?php
declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class SessionMiddleware implements MiddlewareInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Set session timeout to 30 days (2592000 seconds)
            ini_set('session.gc_maxlifetime', '2592000');
            ini_set('session.cookie_lifetime', '2592000');
            session_start();
        }

        // Add session data to request attributes
        $request = $request->withAttribute('session', $_SESSION);

        return $handler->handle($request);
    }
}