<?php
declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        // If it's a preflight request, we can stop here and return 200 with headers
        // However, Slim's architecture often runs middleware LIFO. 
        // If we want to short-circuit, we might need to check if we are the only thing running or if we should return early.
        // But usually, standard middleware passes to handler first.
        // Wait, for OPTIONS requests, we specifically might NOT want to run the inner app/handler 
        // if the inner app doesn't know how to handle OPTIONS for that route (405 Method Not Allowed).

        // Let's modify the flow slightly: check for OPTIONS first.
        if ($request->getMethod() === 'OPTIONS') {
            // Return a fresh 200 OK response immediately, bypassing the application
            $response = new SlimResponse();
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    }
}
