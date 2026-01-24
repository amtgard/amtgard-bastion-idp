<?php

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Utility\Utility;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

class ResourcesController
{
    private TwigEnvironment $twig;
    protected LoggerInterface $logger;


    public function __construct(LoggerInterface $logger,
                            TwigEnvironment $twig,
                            EntityManager $entityManager)
    {
        $this->logger = $logger;
        $this->twig = $twig;
    }

    public function userinfo(Request $request, Response $response): Response {
        $user = Optional::ofNullable(Utility::getAuthenticatedUser())
            ->map(fn($u) => [
                'id' => $u->getUserId(),
                'email' => $u->getEmail()
            ])
            ->orElse(null);

        $response->getBody()->write(json_encode($user));
        return $response;
    }

    /**
     * Display the settings page.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function index(Request $request, Response $response): Response
    {
        $avatarUrl = $_SESSION['avatar_url'] ?? null;
        
        $response->getBody()->write($this->twig->render('settings.twig', [
            'avatarUrl' => $avatarUrl,
        ]));
        
        return $response;
    }
}