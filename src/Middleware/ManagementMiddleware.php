<?php

declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\IdP\Utility\Log\IdpLogging;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

final class ManagementMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly IdpLogging $logging,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $queryParams = $request->getQueryParams();
        $providedKey = $queryParams['key'] ?? null;
        $expectedKey = $_ENV['MANAGEMENT_KEY'] ?? null;
        $path = $request->getUri()->getPath();

        if (empty($expectedKey)) {
            $this->logging->app()->emergency('management endpoint misconfigured', [
                'reason' => 'management_key_missing',
            ]);
            $response = new SlimResponse();
            $response->getBody()->write('Management key not configured in environment.');
            return $response->withStatus(500);
        }

        if (strlen($expectedKey) < 32) {
            $this->logging->app()->emergency('management endpoint misconfigured', [
                'reason' => 'management_key_too_short',
                'key_len' => strlen($expectedKey),
            ]);
            $response = new SlimResponse();
            $response->getBody()->write('Management key invalid (too short).');
            return $response->withStatus(500);
        }

        if ($providedKey !== $expectedKey) {
            $this->logging->security()->alert('management key rejected', [
                'reason' => 'management_key_mismatch',
                'path' => $path,
            ]);
            $response = new SlimResponse();
            $response->getBody()->write('Unauthorized.');
            return $response->withStatus(403);
        }

        $this->logging->audit()->info('management access granted', [
            'path' => $path,
            'audit_action' => 'management_access',
        ]);

        return $handler->handle($request);
    }
}
