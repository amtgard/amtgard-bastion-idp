<?php

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Server\Repositories\AccessTokenRepository;
use Amtgard\IdP\Utility\Utility;
use Couchbase\User;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

class ResourcesController
{
    private TwigEnvironment $twig;
    protected LoggerInterface $logger;
    private ClientRepositoryInterface $clientRepository;
    private Database $database;


    public function __construct(
        LoggerInterface $logger,
        TwigEnvironment $twig,
        EntityManager $entityManager,
        ClientRepositoryInterface $clientRepository,
        Database $database
    ) {
        $this->logger = $logger;
        $this->twig = $twig;
        $this->clientRepository = $clientRepository;
    }

    public function userinfo(Request $request, Response $response): Response
    {
        $user = Optional::ofNullable(Utility::getAuthenticatedUser())
            ->map(fn($u) => [
                'id' => $u->getUserId(),
                'email' => $u->getEmail()
            ])
            ->orElse(null);

        $response->getBody()->write(json_encode($user));
        return $response;
    }

    public function authorizations(Request $request, Response $response): Response
    {
        $user = Utility::getAuthenticatedUser();
        if (!$user) {
            return $response->withStatus(401);
        }

        $clients = $this->clientRepository->findActiveClientsForUser($user->getId());

        $response->getBody()->write(json_encode(array_values($clients)));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Display the profile page.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function profile(Request $request, Response $response): Response
    {
        $avatarUrl = $_SESSION['avatar_url'] ?? null;
        $user = Utility::getAuthenticatedUser();

        $authorizations = [];
        if ($user) {
            $clients = $this->clientRepository->findActiveClientsForUser($user->getId());
        }

        $response->getBody()->write($this->twig->render('profile.twig', [
            'avatarUrl' => $avatarUrl,
            'authorizations' => array_values($clients)
        ]));

        return $response;
    }
}