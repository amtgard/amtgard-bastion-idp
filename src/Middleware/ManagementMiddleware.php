<?php

namespace Amtgard\IdP\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Response as SlimResponse;

class ManagementMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $queryParams = $request->getQueryParams();
        $providedKey = $queryParams['key'] ?? null;
        $expectedKey = $_ENV['MANAGEMENT_KEY'] ?? null;

        if (empty($expectedKey)) {
            // If no key is configured in env, fail secure (or log error)
            // For now, returning 500 Configuration Error could be appropriate, or just 403.
            $response = new SlimResponse();
            $response->getBody()->write('Management key not configured in environment.');
            return $response->withStatus(500);
        }

        if (strlen($expectedKey) < 32) {
            $response = new SlimResponse();
            $response->getBody()->write('Management key invalid (too short).');
            return $response->withStatus(500);
        }

        if ($providedKey !== $expectedKey) {
            $response = new SlimResponse();
            $response->getBody()->write('Unauthorized.');
            return $response->withStatus(403);
        }

        return $handler->handle($request);
    }
}
