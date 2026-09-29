<?php

declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\IdP\Utility\JsonResponseBody;
use Amtgard\IdP\Utility\Security\ConfidentialClientAuthMode;
use Amtgard\IdP\Utility\Security\ConfidentialClientAuthenticator;
use Amtgard\IdP\Utility\Security\MissingIamServiceNamespace;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

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
        try {
            $client = $this->authenticator->authenticate($request, ConfidentialClientAuthMode::RequireIamService);
        } catch (MissingIamServiceNamespace $missing) {
            return JsonResponseBody::writeError(new SlimResponse(), $missing->getMessage(), 403);
        }

        return $handler->handle($request->withAttribute(self::REQUEST_ATTRIBUTE, $client));
    }
}
