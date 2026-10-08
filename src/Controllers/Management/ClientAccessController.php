<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Management;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Utility\Client\ClientIamAdminInput;
use Amtgard\IdP\Utility\Security\CurrentUserResolverInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

class ClientAccessController
{
    public function __construct(
        private LoggerInterface $logger,
        private TwigEnvironment $twig,
        private ClientRepository $clientRepository,
        private ClientAccessRepository $clientAccessRepository,
        private CurrentUserResolverInterface $currentUserResolver,
    ) {
    }

    public function listClients(Request $request, Response $response): Response
    {
        $user = $this->currentUserResolver->resolve();
        if ($user === null) {
            return $response->withHeader('Location', '/auth/login')->withStatus(302);
        }

        $clients = array_map(
            fn ($client) => $this->clientToArray($client),
            $this->clientRepository->findClientsGrantedToUser($user->getId())
        );

        $params = $request->getQueryParams();
        $view = $this->twig->render('management/clients.twig', [
            'clients' => $clients,
            'newClientSecret' => '',
            'viewMode' => 'operator',
            'error' => is_array($params) ? ($params['error'] ?? null) : null,
        ]);
        $response->getBody()->write($view);

        return $response;
    }

    public function updateRedirect(Request $request, Response $response, $id): Response
    {
        $user = $this->currentUserResolver->resolve();
        if ($user === null) {
            return $response->withHeader('Location', '/auth/login')->withStatus(302);
        }

        $clientId = (int) $id;
        $client = $this->clientRepository->fetch($clientId);
        if (!$client || !$this->clientAccessRepository->hasAccess($clientId, $user->getId())) {
            $this->logger->info('operator client update rejected', [
                'reason' => 'no_access',
                'client_id' => $clientId,
            ]);

            return $response->withHeader('Location', '/resources/profile')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $iamInput = $this->iamInputFromForm($data, $response, $clientId);
        if ($iamInput instanceof Response) {
            return $iamInput;
        }

        $this->applyGrantedFields($client, $data, $iamInput);
        EntityManager::getManager()->persist($client);
        $this->logger->info('operator client updated', [
            'client_id' => $clientId,
            'has_iam_service' => $iamInput->iamService !== null,
        ]);

        return $response
            ->withHeader('Location', '/resources/clients')
            ->withStatus(302);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyGrantedFields(Client $client, array $data, ClientIamAdminInput $iamInput): void
    {
        $redirectUri = trim((string) ($data['redirect_uri'] ?? ''));
        if ($redirectUri !== '') {
            $client->setRedirectUri($redirectUri);
        }
        $client->setIamService($iamInput->iamService);
        $client->setIamServiceFormat($iamInput->iamServiceFormat);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function iamInputFromForm(array $data, Response $response, int $clientId): ClientIamAdminInput|Response
    {
        try {
            return ClientIamAdminInput::fromFormData($data);
        } catch (\InvalidArgumentException $invalid) {
            $code = str_contains($invalid->getMessage(), 'iam_service_format') ? 'iam_format' : 'iam_namespace';
            $this->logger->info('Rejected operator client IAM fields', [
                'error' => $code,
                'client_id' => $clientId,
            ]);

            return $response
                ->withHeader('Location', '/resources/clients?error=' . $code)
                ->withStatus(302);
        }
    }

    private function clientToArray($client): array
    {
        return [
            'id' => $client->getId(),
            'identifier' => $client->getIdentifier(),
            'clientSecret' => $client->getClientSecret(),
            'name' => $client->getName(),
            'redirectUri' => $client->getRedirectUri(),
            'isConfidential' => $client->getIsConfidential(),
            'isDev' => $client->getIsDev(),
            'iamService' => $client->getIamService(),
            'iamServiceFormat' => $client->getIamServiceFormat(),
            'accessUsers' => [],
        ];
    }
}
