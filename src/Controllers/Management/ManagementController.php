<?php

namespace Amtgard\IdP\Controllers\Management;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Services\OrkLinkTokenService;
use Amtgard\IdP\Utility\IamServiceFormatValidator;
use Amtgard\IdP\Utility\IamServiceValidator;
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

    public function __construct(
        LoggerInterface $logger,
        TwigEnvironment $twig,
        EntityManager $entityManager,
        AccessTokenRepositoryInterface $accessTokenRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        AuthCodeRepositoryInterface $authCodeRepository,
        ClientRepositoryInterface $clientRepository,
        OrkLinkTokenService $orkLinkTokenService
    ) {
        $this->logger = $logger;
        $this->twig = $twig;
        $this->accessTokens = $accessTokenRepository;
        $this->refreshTokens = $refreshTokenRepository;
        $this->authCodes = $authCodeRepository;
        $this->clientRepository = $clientRepository;
        $this->orkLinkTokenService = $orkLinkTokenService;
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

    public function createClient(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // If client_secret is not provided (e.g. from disabled input), generate one
        $clientSecret = $data['client_secret'] ?? $this->generateClientSecret();
        
        $iamService = IamServiceValidator::validate(
            isset($data['iam_service']) ? trim((string) $data['iam_service']) : null
        );
        $iamServiceFormat = IamServiceFormatValidator::validate(
            isset($data['iam_service_format']) ? trim((string) $data['iam_service_format']) : null
        );

        $client = Client::builder()
            ->identifier($data['client_id'])
            ->clientSecret($clientSecret)
            ->name($data['name'])
            ->redirectUri($data['redirect_uri'])
            ->isConfidential(isset($data['is_confidential']))
            ->isDev(isset($data['is_dev']))
            ->iamService($iamService)
            ->iamServiceFormat($iamServiceFormat)
            ->build();

        EntityManager::getManager()->persist($client);

        return $response
            ->withHeader('Location', '/management/clients')
            ->withStatus(302);
    }
    
    public function updateClient(Request $request, Response $response, $id): Response
    {
        $data = $request->getParsedBody();
        $client = $this->clientRepository->fetch($id);
        
        if ($client) {
            $iamService = IamServiceValidator::validate(
                isset($data['iam_service']) ? trim((string) $data['iam_service']) : null
            );
            $iamServiceFormat = IamServiceFormatValidator::validate(
                isset($data['iam_service_format']) ? trim((string) $data['iam_service_format']) : null
            );

            $client->setIdentifier($data['client_id']);
            $client->setClientSecret($data['client_secret']);
            $client->setName($data['name']);
            $client->setRedirectUri($data['redirect_uri']);
            $client->setIsConfidential(isset($data['is_confidential']));
            $client->setIsDev(isset($data['is_dev']));
            $client->setIamService($iamService);
            $client->setIamServiceFormat($iamServiceFormat);
            
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