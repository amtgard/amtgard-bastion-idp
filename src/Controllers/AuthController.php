<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers;

use Doctrine\ORM\EntityManager;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;
use Amtgard\IdP\Entity\User;
use DateTime;
use Ramsey\Uuid\Uuid;

class AuthController
{
    private EntityManager $entityManager;
    private LoggerInterface $logger;
    private Google $googleProvider;
    private Facebook $facebookProvider;

    public function __construct(
        EntityManager   $entityManager,
        LoggerInterface $logger,
        Google          $googleProvider,
        Facebook        $facebookProvider
    )
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->googleProvider = $googleProvider;
        $this->facebookProvider = $facebookProvider;
    }

    /**
     * Display the login form.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function loginForm(Request $request, Response $response): Response
    {
        $response->getBody()->write('
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Login - Amtgard Identity Provider</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        line-height: 1.6;
                        margin: 0;
                        padding: 20px;
                        color: #333;
                        font-size: 12pt;
                    }
                    .container {
                        max-width: 500px;
                        margin: 0 auto;
                        padding: 20px;
                        border: 1px solid #ddd;
                        border-radius: 5px;
                    }
                    h1 {
                        color: #2c3e50;
                        text-align: center;
                    }
                    .form-group {
                        margin-bottom: 15px;
                    }
                    label {
                        display: block;
                        margin-bottom: 5px;
                    }
                    input[type="email"],
                    input[type="password"] {
                        width: 100%;
                        padding: 8px 0px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                    }
                    button {
                        background: #3498db;
                        color: #fff;
                        padding: 10px 15px;
                        border: none;
                        border-radius: 4px;
                        cursor: pointer;
                        font-size: 12pt;
                        width: 100%;
                    }
                    button:hover {
                        background: #2980b9;
                    }
                    .social-login {
                        margin-top: 20px;
                        text-align: center;
                    }
                    .social-btn {
                        display: inline-block;
                        width: 100%;
                        padding: 10px 0px;
                        margin: 5px 0;
                        border-radius: 4px;
                        text-decoration: none;
                        color: white;
                        text-align: center;
                    }
                    .google-btn {
                        background-color: #DB4437;
                    }
                    .facebook-btn {
                        background-color: #4267B2;
                    }
                    .register-link {
                        text-align: center;
                        margin-top: 15px;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1>Login</h1>
                    <form action="/auth/login" method="post">
                        <div class="form-group">
                            <label for="email">Email:</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password:</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <button type="submit">Login</button>
                    </form>
                    
                    <div class="social-login">
                        <p>Or login with:</p>
                        <a href="/auth/google" class="social-btn google-btn">Google</a>
                        <a href="/auth/facebook" class="social-btn facebook-btn">Facebook</a>
                    </div>
                    
                    <div class="register-link">
                        <p>Don\'t have an account? <a href="/auth/register">Register</a></p>
                    </div>
                </div>
            </body>
            </html>
        ');

        return $response;
    }

    /**
     * Handle login form submission.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['email' => $email]);

        // Check if user exists and password is correct
        if ($user === null || $user->getPassword() === null || !password_verify($password, $user->getPassword())) {
            // Invalid credentials, redirect back to login form
            $response->getBody()->write('
                <script>
                    alert("Invalid email or password");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }

        // Login successful, set session
        $_SESSION['user_id'] = $user->getId();
        $_SESSION['user_email'] = $user->getEmail();
        $_SESSION['user_name'] = $user->getFullName();

        // Redirect to home page
        $routeContext = RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        return $response
            ->withHeader('Location', $routeParser->urlFor('home'))
            ->withStatus(302);
    }

    /**
     * Display the registration form.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function registerForm(Request $request, Response $response): Response
    {
        $response->getBody()->write('
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Register - Amtgard Identity Provider</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        font-size: 12pt;
                        line-height: 1.6;
                        margin: 0;
                        padding: 20px;
                        color: #333;
                    }
                    .container {
                        max-width: 500px;
                        margin: 0 auto;
                        padding: 20px;
                        border: 1px solid #ddd;
                        border-radius: 5px;
                    }
                    h1 {
                        color: #2c3e50;
                        text-align: center;
                    }
                    .form-group {
                        margin-bottom: 15px;
                    }
                    label {
                        display: block;
                        margin-bottom: 5px;
                    }
                    input[type="text"],
                    input[type="email"],
                    input[type="password"] {
                        width: 100%;
                        padding: 8px 0px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                    }
                    button {
                        background: #3498db;
                        color: #fff;
                        padding: 10px 15px;
                        border: none;
                        border-radius: 4px;
                        cursor: pointer;
                        width: 100%;
                        font-size: 12pt;
                    }
                    button:hover {
                        background: #2980b9;
                    }
                    .social-login {
                        margin-top: 20px;
                        text-align: center;
                    }
                    .social-btn {
                        display: inline-block;
                        width: 100%;
                        padding: 10px 0px;
                        margin: 5px 0;
                        border-radius: 4px;
                        text-decoration: none;
                        color: white;
                        text-align: center;
                    }
                    .google-btn {
                        background-color: #DB4437;
                    }
                    .facebook-btn {
                        background-color: #4267B2;
                    }
                    .login-link {
                        text-align: center;
                        margin-top: 15px;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1>Register</h1>
                    <form action="/auth/register" method="post">
                        <div class="form-group">
                            <label for="firstName">First Name:</label>
                            <input type="text" id="firstName" name="firstName" required>
                        </div>
                        <div class="form-group">
                            <label for="lastName">Last Name:</label>
                            <input type="text" id="lastName" name="lastName" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email:</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password:</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label for="confirmPassword">Confirm Password:</label>
                            <input type="password" id="confirmPassword" name="confirmPassword" required>
                        </div>
                        <button type="submit">Register</button>
                    </form>
                    
                    <div class="social-login">
                        <p>Or register with:</p>
                        <a href="/auth/google" class="social-btn google-btn">Google</a>
                        <a href="/auth/facebook" class="social-btn facebook-btn">Facebook</a>
                    </div>
                    
                    <div class="login-link">
                        <p>Already have an account? <a href="/auth/login">Login</a></p>
                    </div>
                </div>
            </body>
            </html>
        ');

        return $response;
    }

    /**
     * Handle registration form submission.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function register(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $firstName = $data['firstName'] ?? '';
        $lastName = $data['lastName'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $confirmPassword = $data['confirmPassword'] ?? '';

        // Validate input
        if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
            $response->getBody()->write('
                <script>
                    alert("All fields are required");
                    window.location.href = "/auth/register";
                </script>
            ');
            return $response;
        }

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response->getBody()->write('
                <script>
                    alert("Invalid email format");
                    window.location.href = "/auth/register";
                </script>
            ');
            return $response;
        }

        // Check if passwords match
        if ($password !== $confirmPassword) {
            $response->getBody()->write('
                <script>
                    alert("Passwords do not match");
                    window.location.href = "/auth/register";
                </script>
            ');
            return $response;
        }

        // Check if email already exists
        $userRepository = $this->entityManager->getRepository(User::class);
        $existingUser = $userRepository->findOneBy(['email' => $email]);

        if ($existingUser !== null) {
            $response->getBody()->write('
                <script>
                    alert("Email already registered");
                    window.location.href = "/auth/register";
                </script>
            ');
            return $response;
        }

        // Create new user
        $user = new User();
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setEmail($email);
        $user->setPassword(password_hash($password, PASSWORD_DEFAULT));

        // Save user to database
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Set session
        $_SESSION['user_id'] = $user->getId();
        $_SESSION['user_email'] = $user->getEmail();
        $_SESSION['user_name'] = $user->getFullName();

        // Redirect to home page
        $routeContext = RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        return $response
            ->withHeader('Location', $routeParser->urlFor('home'))
            ->withStatus(302);
    }

    /**
     * Logout the user.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function logout(Request $request, Response $response): Response
    {
        // Clear session
        session_unset();
        session_destroy();

        // Redirect to home page
        $routeContext = RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        return $response
            ->withHeader('Location', $routeParser->urlFor('home'))
            ->withStatus(302);
    }

    /**
     * Redirect to Google for authentication.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function redirectToGoogle(Request $request, Response $response): Response
    {
        $authUrl = $this->googleProvider->getAuthorizationUrl([
            'scope' => ['email', 'profile'],
        ]);

        // Store state in session for CSRF protection
        $_SESSION['oauth2state'] = $this->googleProvider->getState();

        return $response
            ->withHeader('Location', $authUrl)
            ->withStatus(302);
    }

    /**
     * Handle the callback from Google.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function handleGoogleCallback(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();

        // Check for errors
        if (isset($queryParams['error'])) {
            $response->getBody()->write('
                <script>
                    alert("Google authentication failed: ' . htmlspecialchars($queryParams['error']) . '");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }

        // Validate state to prevent CSRF attacks
        if (empty($queryParams['state']) || ($queryParams['state'] !== $_SESSION['oauth2state'])) {
            unset($_SESSION['oauth2state']);

            $response->getBody()->write('
                <script>
                    alert("Invalid state parameter");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }

        try {
            // Get access token
            $token = $this->googleProvider->getAccessToken('authorization_code', [
                'code' => $queryParams['code']
            ]);

            // Get user details
            $user = $this->googleProvider->getResourceOwner($token);
            $userData = $user->toArray();

            $this->logger->info('Google user data: ' . json_encode($userData));

            // Check if user exists
            $userRepository = $this->entityManager->getRepository(User::class);
            $existingUser = $userRepository->findOneBy(['googleId' => $userData['id']]);
            $this->logger->info('Found existing user: ' . json_encode($existingUser));

            if ($existingUser === null) {
                $this->logger->info('Existing user not found by googleid, checking email: ' . json_encode($existingUser));
                // Check if email exists
                $existingUser = $userRepository->findOneBy(['email' => $userData['email']]);

                if ($existingUser === null) {
                    $this->logger->info('Existing user not found, creating ...');
                    // Create new user
                    $existingUser = new User();
                    $existingUser->setFirstName($userData['given_name']);
                    $existingUser->setLastName($userData['family_name']);
                    $existingUser->setEmail($userData['email']);
                    $existingUser->setGoogleId($userData['id']);
                    $existingUser->setAvatarUrl($userData['picture'] ?? null);

                    $this->entityManager->persist($existingUser);
                } else {
                    // Update existing user with Google ID
                    $existingUser->setGoogleId($userData['id']);
                    $existingUser->setAvatarUrl($userData['picture'] ?? $existingUser->getAvatarUrl());
                }

                $this->entityManager->flush();
            }

            $this->logger->info('User as stored: ' . json_encode($existingUser));
            // Set session
            $_SESSION['user_id'] = $existingUser->getId();
            $_SESSION['user_email'] = $existingUser->getEmail();
            $_SESSION['user_name'] = $existingUser->getFullName();

            // Redirect to home page
            $routeContext = RouteContext::fromRequest($request);
            $routeParser = $routeContext->getRouteParser();

            return $response
                ->withHeader('Location', $routeParser->urlFor('home'))
                ->withStatus(302);

        } catch (\Exception $e) {
            $this->logger->error('Google authentication error: ' . $e->getTraceAsString());

            $response->getBody()->write('
                <script>
                    alert("Authentication error: ' . htmlspecialchars($e->getMessage()) . '");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }
    }

    /**
     * Redirect to Facebook for authentication.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function redirectToFacebook(Request $request, Response $response): Response
    {
        $authUrl = $this->facebookProvider->getAuthorizationUrl([
            'scope' => ['email', 'public_profile'],
        ]);

        // Store state in session for CSRF protection
        $_SESSION['oauth2state'] = $this->facebookProvider->getState();

        return $response
            ->withHeader('Location', $authUrl)
            ->withStatus(302);
    }

    /**
     * Handle the callback from Facebook.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function handleFacebookCallback(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();

        // Check for errors
        if (isset($queryParams['error'])) {
            $response->getBody()->write('
                <script>
                    alert("Facebook authentication failed: ' . htmlspecialchars($queryParams['error']) . '");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }

        // Validate state to prevent CSRF attacks
        if (empty($queryParams['state']) || ($queryParams['state'] !== $_SESSION['oauth2state'])) {
            unset($_SESSION['oauth2state']);

            $response->getBody()->write('
                <script>
                    alert("Invalid state parameter");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }

        try {
            // Get access token
            $token = $this->facebookProvider->getAccessToken('authorization_code', [
                'code' => $queryParams['code']
            ]);

            // Get user details
            $user = $this->facebookProvider->getResourceOwner($token);
            $userData = $user->toArray();

            // Check if user exists
            $userRepository = $this->entityManager->getRepository(User::class);
            $existingUser = $userRepository->findOneBy(['facebookId' => $userData['id']]);

            if ($existingUser === null) {
                // Check if email exists
                $existingUser = $userRepository->findOneBy(['email' => $userData['email']]);

                if ($existingUser === null) {
                    // Create new user
                    $existingUser = new User();
                    $existingUser->setFirstName($userData['first_name']);
                    $existingUser->setLastName($userData['last_name']);
                    $existingUser->setEmail($userData['email']);
                    $existingUser->setFacebookId($userData['id']);
                    
                    // Get profile picture if available
                    if (isset($userData['picture']['data']['url'])) {
                        $existingUser->setAvatarUrl($userData['picture']['data']['url']);
                    }

                    $this->entityManager->persist($existingUser);
                } else {
                    // Update existing user with Facebook ID
                    $existingUser->setFacebookId($userData['id']);
                    
                    // Update avatar if available and user doesn't have one
                    if (isset($userData['picture']['data']['url']) && $existingUser->getAvatarUrl() === null) {
                        $existingUser->setAvatarUrl($userData['picture']['data']['url']);
                    }
                }

                $this->entityManager->flush();
            }

            // Set session
            $_SESSION['user_id'] = $existingUser->getId();
            $_SESSION['user_email'] = $existingUser->getEmail();
            $_SESSION['user_name'] = $existingUser->getFullName();

            // Redirect to home page
            $routeContext = RouteContext::fromRequest($request);
            $routeParser = $routeContext->getRouteParser();

            return $response
                ->withHeader('Location', $routeParser->urlFor('home'))
                ->withStatus(302);

        } catch (\Exception $e) {
            $this->logger->error('Facebook authentication error: ' . $e->getMessage());

            $response->getBody()->write('
                <script>
                    alert("Authentication error: ' . htmlspecialchars($e->getMessage()) . '");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }
    }
}