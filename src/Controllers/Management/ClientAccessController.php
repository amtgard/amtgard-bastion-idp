<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Management;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Utility\Security\CurrentUserResolverInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment as TwigEnvironment;

class ClientAccessController
{
    public function __construct(
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

        $view = $this->twig->render('management/clients.twig', [
            'clients' => $clients,
            'newClientSecret' => '',
            'viewMode' => 'operator',
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
            return $response->withHeader('Location', '/resources/profile')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $redirectUri = trim((string) ($data['redirect_uri'] ?? ''));
        if ($redirectUri !== '') {
            $client->setRedirectUri($redirectUri);
            EntityManager::getManager()->persist($client);
        }

        return $response
            ->withHeader('Location', '/resources/clients')
            ->withStatus(302);
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
