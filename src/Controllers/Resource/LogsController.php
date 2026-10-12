<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Services\LogReaderRateLimiter;
use Amtgard\IdP\Services\LogsQueryService;
use Amtgard\IdP\Utility\JsonResponseBody;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\Log\Sqlite\LogSearchPage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** REST log-read surface backed by hourly SQLite stores. */
final class LogsController
{
    public function __construct(
        private readonly LogsQueryService $logsQueryService,
        private readonly LogReaderRateLimiter $rateLimiter,
        private readonly IdpLogging $idpLogging,
    ) {
    }

    public function traceSearch(Request $request, Response $response): Response
    {
        if ($denied = $this->rateLimitQuery($request, $response, 'resources.logs.trace')) {
            return $denied;
        }

        try {
            $page = $this->logsQueryService->searchTrace($request->getQueryParams());
        } catch (\InvalidArgumentException) {
            return JsonResponseBody::writeError($response, 'Invalid query.', 400);
        }

        return $this->writePage($response, $page);
    }

    public function traceByRequestId(Request $request, Response $response, string $request_id): Response
    {
        if ($denied = $this->rateLimitQuery($request, $response, 'resources.logs.trace.request')) {
            return $denied;
        }

        try {
            $page = $this->logsQueryService->traceByRequestId($request_id);
        } catch (\InvalidArgumentException) {
            return JsonResponseBody::writeError($response, 'Invalid query.', 400);
        }

        if ($page->entries === []) {
            $this->idpLogging->app()->error('logs trace request_id not found', [
                'request_id' => $request_id,
            ]);

            return JsonResponseBody::writeError($response, 'Not found.', 404);
        }

        $this->idpLogging->app()->info('logs trace by request_id served', [
            'request_id' => $request_id,
            'row_count' => count($page->entries),
        ]);

        return $this->writePage($response, $page);
    }

    public function errorSearch(Request $request, Response $response): Response
    {
        if ($denied = $this->rateLimitQuery($request, $response, 'resources.logs.errors')) {
            return $denied;
        }

        try {
            $page = $this->logsQueryService->searchErrors($request->getQueryParams());
        } catch (\InvalidArgumentException) {
            return JsonResponseBody::writeError($response, 'Invalid query.', 400);
        }

        return $this->writePage($response, $page);
    }

    public function stats(Request $request, Response $response): Response
    {
        if ($denied = $this->rateLimitQuery($request, $response, 'resources.logs.stats')) {
            return $denied;
        }

        $rows = $this->logsQueryService->stats($request->getQueryParams());

        return JsonResponseBody::write($response, [
            'stats' => $rows,
            'next_cursor' => null,
        ]);
    }

    public function analyze(Request $request, Response $response): Response
    {
        $subject = $this->subjectFromRequest($request) ?? 'unknown';
        if (! $this->rateLimiter->allowAnalyze($subject)) {
            return $this->rateLimited($response, $subject, 'resources.logs.analyze');
        }

        $body = $request->getParsedBody();
        if (! is_array($body)) {
            $body = [];
        }

        try {
            $page = $this->logsQueryService->analyze($body);
        } catch (\InvalidArgumentException) {
            return JsonResponseBody::writeError($response, 'Invalid analyze request.', 400);
        }

        return $this->writePage($response, $page);
    }

    private function writePage(Response $response, LogSearchPage $page): Response
    {
        $entries = array_map(
            static function (array $entry): array {
                unset($entry['raw_line']);

                return $entry;
            },
            $page->entries,
        );

        return JsonResponseBody::write($response, [
            'entries' => $entries,
            'next_cursor' => $page->nextCursor,
        ]);
    }

    private function rateLimitQuery(Request $request, Response $response, string $route): ?Response
    {
        $subject = $this->subjectFromRequest($request) ?? 'unknown';
        if ($this->rateLimiter->allowQuery($subject)) {
            return null;
        }

        return $this->rateLimited($response, $subject, $route);
    }

    private function rateLimited(Response $response, string $subject, string $route): Response
    {
        $this->idpLogging->app()->error('logs read rate limited', [
            'user_uuid' => $subject,
            'route' => $route,
        ]);

        return JsonResponseBody::writeError($response, 'Too many requests.', 429);
    }

    private function subjectFromRequest(Request $request): ?string
    {
        $jwt = Jwt::validateJwtRequest($request, null);
        if ($jwt === null) {
            return null;
        }

        $payload = Jwt::parseJwt($jwt);

        return isset($payload['sub']) ? (string) $payload['sub'] : null;
    }
}
