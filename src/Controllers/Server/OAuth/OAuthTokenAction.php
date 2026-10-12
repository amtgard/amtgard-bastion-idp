<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server\OAuth;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Amtgard\IdP\Utility\Log\IdpLogging;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OAuthTokenAction
{
    use Builder;
    use Getter;

    private const STEP_TOKEN = 'Token exchange (/oauth/token)';

    protected AuthorizationServer $authorizationServer;

    protected OAuthFlowErrorRenderer $errorRenderer;

    protected IdpLogging $idpLogging;

    public function handle(Request $request, Response $response): Response
    {
        try {
            return $this->authorizationServer->respondToAccessTokenRequest($request, $response);
        } catch (OAuthServerException $exception) {
            $errorResponse = $exception->generateHttpResponse($response);
            $this->idpLogging->oauth()->error('oauth token rejected', [
                'oauth_step' => 'token',
                'http_status' => $errorResponse->getStatusCode(),
                'error' => $exception->getErrorType(),
            ]);

            return $errorResponse;
        } catch (\Throwable $exception) {
            return $this->errorRenderer->renderOAuthTokenError($response, self::STEP_TOKEN, $exception);
        }
    }
}
