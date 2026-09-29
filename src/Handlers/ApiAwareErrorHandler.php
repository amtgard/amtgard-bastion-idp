<?php
declare(strict_types=1);

namespace Amtgard\IdP\Handlers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Handlers\ErrorHandler;
use Slim\Interfaces\CallableResolverInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Throwable;
use Twig\Environment as TwigEnvironment;

/**
 * JSON errors for machine routes. Browser pages get the friendly HTML error page.
 */
class ApiAwareErrorHandler extends ErrorHandler
{
    public function __construct(
        CallableResolverInterface $callableResolver,
        ResponseFactoryInterface $responseFactory,
        ?LoggerInterface $logger = null,
        ?TwigEnvironment $view = null,
    ) {
        parent::__construct($callableResolver, $responseFactory, $logger);
        if ($view !== null) {
            FriendlyHtmlErrorRenderer::attach($this, $view);
        }
    }

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
        $path = $request->getUri()->getPath();
        if (self::isBrowserPage($path)) {
            return 'text/html';
        }
        if (self::isApiPath($path)) {
            return 'application/json';
        }

        return parent::determineContentType($request);
    }

    private static function isBrowserPage(string $path): bool
    {
        return $path === '/resources/profile'
            || str_starts_with($path, '/resources/profile/')
            || $path === '/resources/clients'
            || str_starts_with($path, '/resources/clients/')
            || $path === '/oauth/authorize'
            || $path === '/oauth/approve';
    }

    private static function isApiPath(string $path): bool
    {
        return str_starts_with($path, '/resources') || str_starts_with($path, '/oauth');
    }
}
