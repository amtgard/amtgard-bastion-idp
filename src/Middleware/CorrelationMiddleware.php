<?php

declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\IdP\Utility\Log\RequestLogContext;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Bind X-Request-Id (or a generated id) for log correlation. */
final class CorrelationMiddleware implements MiddlewareInterface
{
    private const HEADER = 'X-Request-Id';
    private const PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $previous = RequestLogContext::id();
        $requestId = $this->resolveId($request);
        RequestLogContext::set($requestId);

        try {
            $response = $handler->handle($request);
        } finally {
            if ($previous === null) {
                RequestLogContext::reset();
            } else {
                RequestLogContext::set($previous);
            }
        }

        return $response->withHeader(self::HEADER, $requestId);
    }

    private function resolveId(ServerRequestInterface $request): string
    {
        $raw = $request->getHeaderLine(self::HEADER);

        return Optional::ofNullable($raw === '' ? null : $raw)
            ->filter(static fn (string $value): bool => (bool) preg_match(self::PATTERN, $value))
            ->orElse(bin2hex(random_bytes(8)));
    }
}
