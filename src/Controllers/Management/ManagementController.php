<?php

namespace Amtgard\IdP\Controllers\Management;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Repositories\AccessTokenRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RefreshTokenRepository;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
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

    public function __construct(
        LoggerInterface $logger,
        TwigEnvironment $twig,
        EntityManager $entityManager,
        AccessTokenRepositoryInterface $accessTokenRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        AuthCodeRepositoryInterface $authCodeRepository
    ) {
        $this->logger = $logger;
        $this->twig = $twig;
        $this->accessTokens = $accessTokenRepository;
        $this->refreshTokens = $refreshTokenRepository;
        $this->authCodes = $authCodeRepository;
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
}