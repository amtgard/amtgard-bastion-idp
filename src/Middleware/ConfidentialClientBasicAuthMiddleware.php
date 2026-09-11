<?php

declare(strict_types=1);


namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Utility\Security\AllowListedConfidentialClientAuthenticator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Server-to-server gate: HTTP Basic auth against the `clients` table, with an
 * env-driven allow-list of which confidential clients may hit this endpoint.
 *
 * Used by POST /resources/link-ork-profile so ORK can assert link updates
 * without first round-tripping through the OAuth code+token dance.
 *
 * EntityManager is the first parameter purely so autowiring configures the ORM
 * singleton before ClientRepository resolves; it is not used directly here.
 */
final class ConfidentialClientBasicAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        EntityManager $entityManager,
        private AllowListedConfidentialClientAuthenticator $authenticator,
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $this->authenticator->authenticate($request);

        return $handler->handle($request);
    }
}
