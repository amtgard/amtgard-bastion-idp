<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server\OAuth;

use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Amtgard\IdP\Utility\Security\RedirectValidator;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment as TwigEnvironment;

final class OAuthApproveAction
{
    use Builder;
    use Getter;

    private const STEP_APPROVAL = 'Client approval (/oauth/approve)';

    protected ClientRepositoryInterface $clientRepository;

    protected UserClientAuthorizationRepository $userClientAuthorizationRepository;

    protected OAuthSessionAuthRequestStore $authRequestStore;

    protected OAuthFlowErrorRenderer $errorRenderer;

    protected TwigEnvironment $view;

    public function handle(Request $request, Response $response): Response
    {
        try {
            if ($request->getMethod() === 'POST') {
                return $this->handlePost($request, $response);
            }

            return $this->renderApprovalForm($request, $response);
        } catch (OAuthServerException $exception) {
            return $this->errorRenderer->renderOAuthFlowError(
                $response,
                self::STEP_APPROVAL,
                $exception->getMessage(),
                true,
                $exception->getHint(),
                $exception->getHttpStatusCode()
            );
        } catch (\Throwable $exception) {
            return $this->errorRenderer->renderOAuthFlowError(
                $response,
                self::STEP_APPROVAL,
                'We could not complete client approval. Please try again or contact an administrator.',
                false,
                null,
                500,
                $exception
            );
        }
    }

    private function handlePost(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $action = $data['action'] ?? null;
        $callback = RedirectValidator::sanitize($data['callback'] ?? '/', '/');

        if ($action === 'allow') {
            $this->authRequestStore->markApproved();

            $authRequest = $this->authRequestStore->load();
            if ($authRequest !== null) {
                $clientId = $authRequest->getClient()->getIdentifier();

                /** @var \Amtgard\IdP\Persistence\Server\Entities\Repository\Client $clientEntity */
                $clientEntity = $this->clientRepository->fetchBy('identifier', $clientId);

                $sessionUserId = $this->authRequestStore->sessionUserId();
                if ($sessionUserId !== null && $clientEntity) {
                    $this->userClientAuthorizationRepository->authorize($sessionUserId, $clientEntity->getId());
                }
            }

            return $response
                ->withStatus(302)
                ->withHeader('Location', $callback);
        }

        $this->authRequestStore->clearAuthRequest();

        return $response
            ->withStatus(302)
            ->withHeader('Location', '/');
    }

    private function renderApprovalForm(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $scopeString = $queryParams['scope'] ?? '';
        $scopes = !empty($scopeString) ? explode(',', $scopeString) : [];
        $clientId = $queryParams['client_id'] ?? 'Unknown Application';
        $client = $this->clientRepository->getClientEntity($clientId);
        $callback = RedirectValidator::sanitize($queryParams['callback'] ?? '/', '/');

        $response->getBody()->write(
            $this->view->render('oauth_approve.twig', [
                'client_name' => $client->getName(),
                'scopes' => $scopes,
                'callback' => $callback,
            ])
        );

        return $response;
    }
}
