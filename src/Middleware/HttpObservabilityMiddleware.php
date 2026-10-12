<?php

declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\IdP\Utility\Log\IdpLogging;
use Monolog\Level;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/** One summary log line per HTTP request (status → level mapping). */
final class HttpObservabilityMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly IdpLogging $logging,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $started = hrtime(true);
        $response = $handler->handle($request);
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $status = $response->getStatusCode();
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        $logger = $this->loggerFor($path, $status);
        $level = $this->levelForStatus($status);

        $logger->log($level, 'http.request.completed', [
            'http_status' => $status,
            'route' => $path,
            'method' => $method,
            'duration_ms' => $durationMs,
        ]);

        return $response;
    }

    private function loggerFor(string $path, int $status): LoggerInterface
    {
        if ($status === 401 || $status === 403) {
            return $this->logging->security();
        }

        if (str_starts_with($path, '/oauth') || str_starts_with($path, '/auth')) {
            return $this->logging->oauth();
        }

        return $this->logging->app();
    }

    private function levelForStatus(int $status): Level
    {
        if ($status >= 500) {
            return Level::Critical;
        }

        if ($status === 404) {
            return Level::Notice;
        }

        if ($status >= 400) {
            return Level::Error;
        }

        return Level::Info;
    }
}
