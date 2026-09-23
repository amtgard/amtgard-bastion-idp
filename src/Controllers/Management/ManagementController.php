<?php

declare(strict_types=1);


namespace Amtgard\IdP\Controllers\Management;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Entities\Repository\ClientAccess;
use Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Services\OrkLinkTokenService;
use Amtgard\IdP\Utility\Client\ClientIamAdminInput;
use Amtgard\IdP\Utility\JsonResponseBody;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

class ManagementController
{
    private TwigEnvironment $twig;
    protected LoggerInterface $logger;
    private AccessTokenRepositoryInterface $accessTokens;
    private RefreshTokenRepositoryInterface $refreshTokens;
    private AuthCodeRepositoryInterface $authCodes;
    private ClientRepository $clientRepository;
    private OrkLinkTokenService $orkLinkTokenService;
    private ClientAccessRepository $clientAccessRepository;
    private UserRepository $userRepository;

    public function __construct(
        LoggerInterface $logger,
        TwigEnvironment $twig,
        AccessTokenRepositoryInterface $accessTokenRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        AuthCodeRepositoryInterface $authCodeRepository,
        ClientRepositoryInterface $clientRepository,
        OrkLinkTokenService $orkLinkTokenService,
        ClientAccessRepository $clientAccessRepository,
        UserRepository $userRepository,
    ) {
        $this->logger = $logger;
        $this->twig = $twig;
        $this->accessTokens = $accessTokenRepository;
        $this->refreshTokens = $refreshTokenRepository;
        $this->authCodes = $authCodeRepository;
        $this->clientRepository = $clientRepository;
        $this->orkLinkTokenService = $orkLinkTokenService;
        $this->clientAccessRepository = $clientAccessRepository;
        $this->userRepository = $userRepository;
    }

    public function cleanTokens(Request $request, Response $response): Response
    {
        $this->logger->info('Starting token cleanup');

        try {
            $this->accessTokens->deleteExpiredTokens();
            $this->refreshTokens->deleteExpiredTokens();
            $this->refreshTokens->deleteOrphanedRefreshTokens();
            $this->authCodes->deleteExpiredAuthCodes();

            // M1: clear consumed handoff jti rows older than the replay window.
            // 1 hour generously exceeds the 15-minute exp; once a jti row is
            // older than the token's max lifetime, replay is impossible anyway.
            $this->orkLinkTokenService->cleanExpiredJti();

            $response->getBody()->write('Tokens cleaned successfully');
            return $response->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Token cleanup failed: ' . $e->getMessage());
            $response->getBody()->write('Token cleanup failed');
            return $response->withStatus(500);
        }
    }

    public function listClients(Request $request, Response $response): Response
    {
        $clients = $this->clientRepository->getAllClients();
        $clientData = array_map(function($client) {
            return $this->clientToArray($client, $this->accessUsersForClient($client->getId()));
        }, $clients);
        
        $newClientSecret = $this->generateClientSecret();

        $view = $this->twig->render('management/clients.twig', [
            'clients' => $clientData,
            'newClientSecret' => $newClientSecret,
            'viewMode' => 'admin',
        ]);
        $response->getBody()->write($view);
        return $response;
    }

    public function createClient(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        // If client_secret is not provided (e.g. from disabled input), generate one
        $clientSecret = $data['client_secret'] ?? $this->generateClientSecret();
        $iamInput = ClientIamAdminInput::fromFormData($data);

        $client = Client::builder()
            ->identifier($data['client_id'])
            ->clientSecret($clientSecret)
            ->name($data['name'])
            ->redirectUri($data['redirect_uri'])
            ->isConfidential(isset($data['is_confidential']))
            ->isDev(isset($data['is_dev']))
            ->iamService($iamInput->iamService)
            ->iamServiceFormat($iamInput->iamServiceFormat)
            ->build();

        EntityManager::getManager()->persist($client);

        return $response
            ->withHeader('Location', '/management/clients')
            ->withStatus(302);
    }
    
    public function updateClient(Request $request, Response $response, $id): Response
    {
        $data = (array) $request->getParsedBody();
        $client = $this->clientRepository->fetch($id);

        if ($client) {
            $iamInput = ClientIamAdminInput::fromFormData($data);

            $client->setIdentifier($data['client_id']);
            $client->setClientSecret($data['client_secret']);
            $client->setName($data['name']);
            $client->setRedirectUri($data['redirect_uri']);
            $client->setIsConfidential(isset($data['is_confidential']));
            $client->setIsDev(isset($data['is_dev']));
            $client->setIamService($iamInput->iamService);
            $client->setIamServiceFormat($iamInput->iamServiceFormat);

            EntityManager::getManager()->persist($client);
        }

        return $response
            ->withHeader('Location', '/management/clients')
            ->withStatus(302);
    }

    public function searchUsers(Request $request, Response $response): Response
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if (strlen($query) < 2) {
            return JsonResponseBody::write($response, ['users' => []]);
        }

        return JsonResponseBody::write($response, [
            'users' => $this->userRepository->searchByEmailPrefix($query),
        ]);
    }

    public function addClientAccess(Request $request, Response $response, $id): Response
    {
        $clientId = (int) $id;
        $client = $this->clientRepository->fetch($clientId);
        if (!$client) {
            return JsonResponseBody::writeError($response, 'Client not found', 404);
        }

        $data = (array) $request->getParsedBody();
        $user = $this->resolveAccessUser($data);
        if ($user === null) {
            return JsonResponseBody::writeError($response, 'User not found', 404);
        }

        $this->clientAccessRepository->grant($clientId, $user->getId());

        return JsonResponseBody::write($response, [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
        ]);
    }

    public function removeClientAccess(Request $request, Response $response, $id, $userId): Response
    {
        $clientId = (int) $id;
        $client = $this->clientRepository->fetch($clientId);
        if (!$client) {
            return JsonResponseBody::writeError($response, 'Client not found', 404);
        }

        $this->clientAccessRepository->revoke($clientId, (int) $userId);

        return JsonResponseBody::write($response, ['ok' => true]);
    }

    /**
     * @return array<int, array{id: int, email: string}>
     */
    private function accessUsersForClient(?int $clientId): array
    {
        if (!$clientId) {
            return [];
        }

        $users = [];
        foreach ($this->clientAccessRepository->findByClientId($clientId) as $row) {
            /** @var ClientAccess $row */
            $user = $this->userRepository->findUserById($row->getUserId());
            if (!$user instanceof UserEntity || !$user->getEmail()) {
                continue;
            }
            $users[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
            ];
        }

        return $users;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveAccessUser(array $data): ?UserEntity
    {
        if (isset($data['user_id']) && $data['user_id'] !== '') {
            return $this->userRepository->findUserById((int) $data['user_id']);
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return null;
        }

        return $this->userRepository->getUserByEmail($email);
    }

    private function clientToArray(Client $client, array $accessUsers = []): array
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
            'accessUsers' => $accessUsers,
        ];
    }

    private function generateClientSecret($length = 32)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}