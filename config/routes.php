<?php
declare(strict_types=1);

use Amtgard\IdP\Controllers\Client\AuthController;
use Amtgard\IdP\Controllers\Client\FacebookAuthController;
use Amtgard\IdP\Controllers\Client\GoogleAuthController;
use Amtgard\IdP\Controllers\HomeController;
use Amtgard\IdP\Controllers\OAuth2Controller;
use Amtgard\IdP\Controllers\Server\OAuth2ServerController;
use Amtgard\IdP\Controllers\Settings\SettingsController;
use Amtgard\IdP\Controllers\UserController;
use Amtgard\IdP\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    // Home page
    $app->get('/', [HomeController::class, 'index'])->setName('home');

    $app->group('/settings', function (RouteCollectorProxy $group) {
       $group->get('', [SettingsController::class, 'index'])->setName('settings');
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
    });

    // OAuth2 server routes
    $app->group('/oauth', function (RouteCollectorProxy $group) {
        // Authorization endpoint
        $group->get('/authorize', [OAuth2ServerController::class, 'authorize'])->setName('oauth.authorize');
        $group->post('/authorize', [OAuth2ServerController::class, 'authorizePost']);
        
        // Token endpoint
        $group->post('/token', [OAuth2ServerController::class, 'token'])->setName('oauth.token');

        // Token endpoint
        $group->post('/approve', [OAuth2ServerController::class, 'approve'])->setName('oauth.approve');

        // UserRepository info endpoint (protected by access token)
        $group->get('/userinfo', [OAuth2ServerController::class, 'userInfo'])
            ->add(new AuthMiddleware())
            ->setName('oauth.userinfo');
    });
};