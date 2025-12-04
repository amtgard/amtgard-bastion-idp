<?php
declare(strict_types=1);

use Amtgard\IdP\Controllers\Client\AuthController;
use Amtgard\IdP\Controllers\Client\FacebookAuthController;
use Amtgard\IdP\Controllers\Client\GoogleAuthController;
use Amtgard\IdP\Controllers\HomeController;
use Amtgard\IdP\Controllers\OAuth2Controller;
use Amtgard\IdP\Controllers\UserController;
use Amtgard\IdP\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    // Home page
    $app->get('/', [HomeController::class, 'index'])->setName('home');

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
        $group->get('/authorize', [OAuth2Controller::class, 'authorize'])->setName('oauth.authorize');
        $group->post('/authorize', [OAuth2Controller::class, 'authorizePost']);
        
        // Token endpoint
        $group->post('/token', [OAuth2Controller::class, 'token'])->setName('oauth.token');
        
        // UserRepository info endpoint (protected by access token)
        $group->get('/userinfo', [OAuth2Controller::class, 'userInfo'])
            ->add(new AuthMiddleware())
            ->setName('oauth.userinfo');
    });

    // UserRepository management routes (protected)
    $app->group('/user', function (RouteCollectorProxy $group) {
        $group->get('', [UserController::class, 'profile'])->setName('user.profile');
        $group->get('/edit', [UserController::class, 'editForm'])->setName('user.edit');
        $group->post('/edit', [UserController::class, 'update']);
    })->add(new AuthMiddleware());
};