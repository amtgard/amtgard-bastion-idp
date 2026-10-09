<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server\OAuth;

use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Psr\Http\Message\ResponseInterface as Response;
use Twig\Environment as TwigEnvironment;

final class OAuthFlowErrorRenderer
{
    use Builder;
    use Getter;

    protected IdpLogging $idpLogging;

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
        $oauthStep = $this->oauthStepKey($step);
        if ($exception !== null) {
            $this->idpLogging->oauth()->critical('oauth flow internal error', [
                'oauth_step' => $oauthStep,
                'http_status' => $status,
                'exception_class' => $exception::class,
            ]);
        } elseif ($status >= 500) {
            $this->idpLogging->oauth()->critical('oauth flow server error', [
                'oauth_step' => $oauthStep,
                'http_status' => $status,
                'is_protocol_error' => $isProtocolError,
            ]);
        } else {
            $this->idpLogging->oauth()->error('oauth flow protocol error', [
                'oauth_step' => $oauthStep,
                'http_status' => $status,
                'is_protocol_error' => $isProtocolError,
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
        $this->idpLogging->oauth()->critical('oauth token internal error', [
            'oauth_step' => $this->oauthStepKey($step),
            'exception_class' => $exception::class,
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

    private function oauthStepKey(string $step): string
    {
        if (str_contains($step, '/oauth/token')) {
            return 'token';
        }
        if (str_contains($step, '/oauth/approve')) {
            return 'approve';
        }
        if (str_contains($step, '/oauth/authorize')) {
            return 'authorize';
        }

        return $step;
    }
}
