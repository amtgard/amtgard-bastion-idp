<?php
declare(strict_types=1);

namespace Amtgard\IdP\Handlers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Handlers\ErrorHandler;
use Throwable;

/**
 * JSON errors for API routes. HTML (including debug traces) stays on HTML pages only.
 */
class ApiAwareErrorHandler extends ErrorHandler
{
    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        $this->contentType = null;

        return parent::__invoke($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
    }

    protected function determineContentType(ServerRequestInterface $request): ?string
    {
        if (self::isApiPath($request->getUri()->getPath())) {
            return 'application/json';
        }

        return parent::determineContentType($request);
    }

    private static function isApiPath(string $path): bool
    {
        return str_starts_with($path, '/resources') || str_starts_with($path, '/oauth');
    }
}
