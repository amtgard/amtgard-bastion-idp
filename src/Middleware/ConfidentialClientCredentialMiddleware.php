<?php

declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\IdP\Utility\Security\ConfidentialClientAuthenticator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Confidential OAuth client credentials only (no iam_service requirement).
 */
class ConfidentialClientCredentialMiddleware implements MiddlewareInterface
{
    public const REQUEST_ATTRIBUTE = ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE;

    public function __construct(
        private ConfidentialClientAuthenticator $authenticator,
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $client = $this->authenticator->authenticate($request, false);

        return $handler->handle($request->withAttribute(self::REQUEST_ATTRIBUTE, $client));
    }
}
