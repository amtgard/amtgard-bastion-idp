<?php

declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\IdP\Utility\Security\ConfidentialClientAuthMode;
use Amtgard\IdP\Utility\Security\ConfidentialClientAuthenticator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Server-to-server gate for registered confidential OAuth clients with an
 * assigned IAM service namespace. Sets request attribute `registered_client`.
 */
class ConfidentialClientAuthMiddleware implements MiddlewareInterface
{
    public const REQUEST_ATTRIBUTE = 'registered_client';

    public function __construct(
        private ConfidentialClientAuthenticator $authenticator,
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $client = $this->authenticator->authenticate($request, ConfidentialClientAuthMode::RequireIamService);

        return $handler->handle($request->withAttribute(self::REQUEST_ATTRIBUTE, $client));
    }
}
