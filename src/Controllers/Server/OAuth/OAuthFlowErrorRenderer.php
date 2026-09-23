<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server\OAuth;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

final class OAuthFlowErrorRenderer
{
    use Builder;
    use Getter;

    protected LoggerInterface $logger;

    protected TwigEnvironment $view;

    public function renderOAuthFlowError(
        Response $response,
        string $step,
        string $message,
        bool $isProtocolError,
        ?string $hint = null,
        int $status = 500,
        ?\Throwable $exception = null
    ): Response {
        if ($exception !== null) {
            $this->logger->error('OAuth flow internal error', [
                'step' => $step,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        $response->getBody()->write(
            $this->view->render('oauth_error.twig', [
                'title' => $isProtocolError ? 'OAuth Authorization Error' : 'Authorization Unavailable',
                'step' => $step,
                'message' => $message,
                'hint' => $hint,
                'is_protocol_error' => $isProtocolError,
            ])
        );

        return $response->withStatus($status);
    }

    public function renderOAuthTokenError(Response $response, string $step, \Throwable $exception): Response
    {
        $this->logger->error('OAuth token internal error', [
            'step' => $step,
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $response->getBody()->write(json_encode([
            'error' => 'server_error',
            'error_description' => 'The authorization server encountered an internal error during token exchange.',
            'oauth_step' => $step,
            'error_type' => 'internal_server_error',
        ]));

        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json');
    }
}
