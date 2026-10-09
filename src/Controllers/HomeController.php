<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers;

use Amtgard\IdP\Utility\Log\IdpLogging;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment as TwigEnvironment;

class HomeController
{
    private TwigEnvironment $twig;
    private IdpLogging $idpLogging;

    public function __construct(TwigEnvironment $twig, IdpLogging $idpLogging)
    {
        $this->twig = $twig;
        $this->idpLogging = $idpLogging;
    }

    /**
     * Display the home page.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function index(Request $request, Response $response): Response
    {
        $isLoggedIn = isset($_SESSION['user_id']);
        $avatarUrl = $_SESSION['avatar_url'] ?? null;

        $this->idpLogging->app()->debug('home page render', [
            'logged_in' => $isLoggedIn,
        ]);
        
        $response->getBody()->write($this->twig->render('home.twig', [
            'isLoggedIn' => $isLoggedIn,
            'avatarUrl' => $avatarUrl,
        ]));
        
        return $response;
    }
}
