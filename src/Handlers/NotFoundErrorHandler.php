<?php
declare(strict_types=1);

namespace Amtgard\IdP\Handlers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Handlers\ErrorHandler;
use Slim\Interfaces\CallableResolverInterface;
use Twig\Environment as TwigEnvironment;

class NotFoundErrorHandler extends ErrorHandler
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

    private const USER_AGENT_MAX_LENGTH = 256;

    protected function writeToErrorLog(): void
    {
        if (!$this->logErrors) {
            return;
        }

        $uri = $this->request->getUri();
        $context = [
            'method'      => $this->request->getMethod(),
            'path'        => $uri->getPath(),
            'ip'          => self::clientIp($this->request),
            'user_agent'  => self::truncateUserAgent($this->request->getHeaderLine('User-Agent')),
        ];

        $query = $uri->getQuery();
        if ($query !== '') {
            $context['query'] = $query;
        }

        $this->logger->notice('404 Not Found', $context);
    }

    private static function clientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    private static function truncateUserAgent(string $userAgent): string
    {
        if (strlen($userAgent) <= self::USER_AGENT_MAX_LENGTH) {
            return $userAgent;
        }

        return substr($userAgent, 0, self::USER_AGENT_MAX_LENGTH) . '…';
    }
}
