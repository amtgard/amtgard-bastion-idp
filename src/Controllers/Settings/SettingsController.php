<?php

namespace Amtgard\IdP\Controllers\Settings;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment as TwigEnvironment;

class SettingsController
{
    private TwigEnvironment $twig;

    public function __construct(TwigEnvironment $twig)
    {
        $this->twig = $twig;
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