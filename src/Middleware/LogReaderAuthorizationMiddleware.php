<?php

declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\PolicyFactory;
use Amtgard\IdP\Models\Orn\IdpRequirement;
use Amtgard\IdP\Utility\JsonResponseBody;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteContext;

/** Chain-of-responsibility ORN gate for log-read routes (AnalyzeLogs satisfies all). */
final class LogReaderAuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly IdpLogging $idpLogging,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requiredOrn = $this->requiredOrnForRoute($this->routeName($request));
        if ($requiredOrn === null) {
            return $handler->handle($request);
        }

        $security = $this->idpLogging->security();
        $jwt = Jwt::validateJwtRequest($request, $security);
        if ($jwt === null) {
            $security->error('log reader auth rejected', [
                'failure_reason' => 'jwt_required',
                'orn' => $requiredOrn,
                'route' => $this->routeName($request),
            ]);

            return JsonResponseBody::writeError((new ResponseFactory())->createResponse(), 'Not authorized.', 401);
        }

        $payload = Jwt::parseJwt($jwt, $security);
        if ($payload === null || ! Jwt::isAuthorizationPayload($payload)) {
            $security->error('log reader auth rejected', [
                'failure_reason' => 'authorization_jwt_required',
                'orn' => $requiredOrn,
                'route' => $this->routeName($request),
            ]);

            return JsonResponseBody::writeError((new ResponseFactory())->createResponse(), 'Not authorized.', 401);
        }

        $policyJson = $payload['policy'] ?? '[]';
        $policy = PolicyFactory::fromOrn(json_decode(is_string($policyJson) ? $policyJson : '[]', true));
        $requirement = new IdpRequirement(ServiceCatalog::Idp, $requiredOrn);
        $analyze = new IdpRequirement(ServiceCatalog::Idp, 'Idp:0::::IDP/AnalyzeLogs');

        if (! $policy->isAuthorized($requirement) && ! $policy->isAuthorized($analyze)) {
            $security->error('log reader orn denied', [
                'user_uuid' => isset($payload['sub']) ? (string) $payload['sub'] : null,
                'client_id' => isset($payload['aud']) ? (string) $payload['aud'] : null,
                'orn' => $requiredOrn,
                'route' => $this->routeName($request),
            ]);

            return JsonResponseBody::writeError((new ResponseFactory())->createResponse(), 'Forbidden.', 403);
        }

        return $handler->handle($request);
    }

    private function routeName(ServerRequestInterface $request): ?string
    {
        $route = RouteContext::fromRequest($request)->getRoute();

        return $route?->getName();
    }

    private function requiredOrnForRoute(?string $routeName): ?string
    {
        return match ($routeName) {
            'resources.logs.errors' => 'Idp:0::::IDP/ReadErrorLogs',
            'resources.logs.analyze' => 'Idp:0::::IDP/AnalyzeLogs',
            'resources.logs.trace',
            'resources.logs.trace.request',
            'resources.logs.stats' => 'Idp:0::::IDP/ReadTraceLogs',
            default => null,
        };
    }
}
