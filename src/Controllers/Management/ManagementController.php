<?php

namespace Amtgard\IdP\Controllers\Management;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
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

    public function __construct(
        LoggerInterface $logger,
        TwigEnvironment $twig,
        EntityManager $entityManager,
        AccessTokenRepositoryInterface $accessTokenRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        AuthCodeRepositoryInterface $authCodeRepository,
        ClientRepositoryInterface $clientRepository
    ) {
        $this->logger = $logger;
        $this->twig = $twig;
        $this->accessTokens = $accessTokenRepository;
        $this->refreshTokens = $refreshTokenRepository;
        $this->authCodes = $authCodeRepository;
        $this->clientRepository = $clientRepository;
    }

    public function cleanTokens($request, $response)
    {
        $this->logger->info('Starting token cleanup');

        try {
            $this->accessTokens->deleteExpiredTokens();
            $this->refreshTokens->deleteExpiredTokens();
            $this->refreshTokens->deleteOrphanedRefreshTokens();
            $this->authCodes->deleteExpiredAuthCodes();

            $response->getBody()->write('Tokens cleaned successfully');
            return $response->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Token cleanup failed: ' . $e->getMessage());
            $response->getBody()->write('Token cleanup failed');
            return $response->withStatus(500);
        }
    }

    public function listClients($request, $response)
    {
        $clients = $this->clientRepository->getAllClients();
        $clientData = array_map(function($client) {
            return [
                'id' => $client->getId(),
                'identifier' => $client->getIdentifier(),
                'clientSecret' => $client->getClientSecret(),
                'name' => $client->getName(),
                'redirectUri' => $client->getRedirectUri(),
                'isConfidential' => $client->getIsConfidential(),
                'isDev' => $client->getIsDev(),
            ];
        }, $clients);
        
        $newClientSecret = $this->generateClientSecret();

        $view = $this->twig->render('management/clients.twig', [
            'clients' => $clientData,
            'newClientSecret' => $newClientSecret
        ]);
        $response->getBody()->write($view);
        return $response;
    }

    public function createClient($request, $response)
    {
        $data = $request->getParsedBody();
        
        // If client_secret is not provided (e.g. from disabled input), generate one
        $clientSecret = $data['client_secret'] ?? $this->generateClientSecret();
        
        $client = Client::builder()
            ->identifier($data['client_id'])
            ->clientSecret($clientSecret)
            ->name($data['name'])
            ->redirectUri($data['redirect_uri'])
            ->isConfidential(isset($data['is_confidential']))
            ->isDev(isset($data['is_dev']))
            ->build();

        EntityManager::getManager()->persist($client);

        return $response
            ->withHeader('Location', '/management/clients')
            ->withStatus(302);
    }
    
    public function updateClient($request, $response, $id)
    {
        $data = $request->getParsedBody();
        $client = $this->clientRepository->fetch($id);
        
        if ($client) {
            $client->setIdentifier($data['client_id']);
            $client->setClientSecret($data['client_secret']);
            $client->setName($data['name']);
            $client->setRedirectUri($data['redirect_uri']);
            $client->setIsConfidential(isset($data['is_confidential']));
            $client->setIsDev(isset($data['is_dev']));
            
            EntityManager::getManager()->persist($client);
        }

        return $response
            ->withHeader('Location', '/management/clients')
            ->withStatus(302);
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