<?php
declare(strict_types=1);

use Amtgard\IdP\Controllers\Client\AuthController;
use Amtgard\IdP\Controllers\Client\FacebookAuthController;
use Amtgard\IdP\Controllers\Client\GoogleAuthController;
use Amtgard\IdP\Controllers\HomeController;
use Amtgard\IdP\Controllers\Server\OAuth2ServerController;
use Amtgard\IdP\Controllers\Management\ManagementController;
use Amtgard\IdP\Controllers\Resource\ResourcesController;
use Amtgard\IdP\Middleware\LocalAdminUserMiddleware;
use Amtgard\IdP\Middleware\LocalIdpAuthMiddleware;
use Amtgard\IdP\Middleware\ClientRestrictedAuthMiddleware;
use Amtgard\IdP\Middleware\ManagementMiddleware;
use Amtgard\IdP\Middleware\CachedJwtLocalIdpAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    // Home page
    $app->get('/', [HomeController::class, 'index'])->setName('home');

    $app->group('/resources', function (RouteCollectorProxy $group) {
        $group->get('/validate', [ResourcesController::class, 'validate'])
            ->add(CachedJwtLocalIdpAuthMiddleware::class)
            ->setName('resources.validate');

        // UserRepository info endpoint (protected by access token)
        $group->get('/userinfo', [ResourcesController::class, 'userInfo'])
            ->add(CachedJwtLocalIdpAuthMiddleware::class)
            ->setName('resources.userinfo');

        $group->get('/profile', [ResourcesController::class, 'profile'])
            ->add(LocalIdpAuthMiddleware::class)
            ->setName('resources.profile');

        $group->get('/authorizations', [ResourcesController::class, 'authorizations'])
            ->add(ClientRestrictedAuthMiddleware::class)
            ->setName('resources.authorizations');

        $group->post('/profile/link-ork', [ResourcesController::class, 'linkOrkAccount'])
            ->add(ClientRestrictedAuthMiddleware::class)
            ->setName('resources.profile.link_ork');

        $group->post('/profile/refresh-ork', [ResourcesController::class, 'refreshOrkAccount'])
            ->add(ClientRestrictedAuthMiddleware::class)
            ->setName('resources.profile.refresh_ork');

        $group->post('/profile/revoke', [ResourcesController::class, 'revokeAuthorization'])
            ->add(ClientRestrictedAuthMiddleware::class)
            ->setName('resources.profile.revoke');
    });

    // Authentication routes
    $app->group('/auth', function (RouteCollectorProxy $group) {
        // Login form
        $group->get('/login', [AuthController::class, 'loginForm'])->setName('auth.login');
        $group->post('/login', [AuthController::class, 'login']);

        // Registration form
        $group->get('/register', [AuthController::class, 'registerForm'])->setName('auth.register');
        $group->post('/register', [AuthController::class, 'register']);

        // Logout
        $group->get('/logout', [AuthController::class, 'logout'])->setName('auth.logout');

        // Social login routes
        $group->get('/google', [GoogleAuthController::class, 'redirectToGoogle'])->setName('auth.google');
        $group->get('/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])->setName('auth.google.callback');

        $group->get('/facebook', [FacebookAuthController::class, 'redirectToFacebook'])->setName('auth.facebook');
        $group->get('/facebook/callback', [FacebookAuthController::class, 'handleFacebookCallback'])->setName('auth.facebook.callback');

        $group->get('/discord', [\Amtgard\IdP\Controllers\Client\DiscordAuthController::class, 'redirectToDiscord'])->setName('auth.discord');
        $group->get('/discord/callback', [\Amtgard\IdP\Controllers\Client\DiscordAuthController::class, 'handleDiscordCallback'])->setName('auth.discord.callback');
    });

    // Management routes
    $app->group('/management', function (RouteCollectorProxy $group) {
        $group->get('/cleantokens', [ManagementController::class, 'cleanTokens'])
            ->add(ManagementMiddleware::class)
            ->setName('management.cleantokens');

        $group->get('/clients', [ManagementController::class, 'listClients'])
            ->add(LocalIdpAuthMiddleware::class)
            ->add(LocalAdminUserMiddleware::class)
            ->setName('management.clients');
            
        $group->post('/clients', [ManagementController::class, 'createClient'])
            ->add(LocalIdpAuthMiddleware::class)
            ->add(LocalAdminUserMiddleware::class)
            ->setName('management.clients.create');
            
        $group->post('/clients/{id}', [ManagementController::class, 'updateClient'])
            ->add(LocalIdpAuthMiddleware::class)
            ->add(LocalAdminUserMiddleware::class)
            ->setName('management.clients.update');
    });

    // OAuth2 server routes
    $app->group('/oauth', function (RouteCollectorProxy $group) {
        // Authorization endpoint
        $group->get('/authorize', [OAuth2ServerController::class, 'authorize'])->setName('oauth.authorize');
        $group->post('/authorize', [OAuth2ServerController::class, 'authorizePost']);

        // Token endpoint
        $group->post('/token', [OAuth2ServerController::class, 'token'])->setName('oauth.token');

        // Token endpoint
        $group->map(['GET', 'POST'], '/approve', [OAuth2ServerController::class, 'approve'])->setName('oauth.approve');

    });
};