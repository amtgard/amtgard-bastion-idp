<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment as TwigEnvironment;

class HomeController
{
    private TwigEnvironment $twig;

    public function __construct(TwigEnvironment $twig)
    {
        $this->twig = $twig;
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
        
        $response->getBody()->write($this->twig->render('home.twig', [
            'isLoggedIn' => $isLoggedIn,
        ]));
        
        return $response;
    }
}