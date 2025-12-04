<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Client;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\AuthClient\Repositories\UserRepository;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;
use Twig\Environment as TwigEnvironment;

class AuthController
{
    private UserRepository $users;
    private LoggerInterface $logger;
    private Google $googleProvider;
    private Facebook $facebookProvider;
    private TwigEnvironment $twig;

    public function __construct(
        EntityManager $entityManager,
        UserRepository $users,
        LoggerInterface $logger,
        Google          $googleProvider,
        Facebook        $facebookProvider,
        TwigEnvironment $twig
    )
    {
        $this->users = $users;
        $this->logger = $logger;
        $this->googleProvider = $googleProvider;
        $this->facebookProvider = $facebookProvider;
        $this->twig = $twig;
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
        $response->getBody()->write($this->twig->render('login_form.twig', [
            'redirect' => $request->getQueryParams()['redirect'],
            'jwtpublickey' => $request->getQueryParams()['jwtpublickey']
        ]));
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

        $userRepository = $this->entityManager->getRepository(UserRepository::class);
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
        $response->getBody()->write($this->twig->render('register_form.twig'));
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
        if ($this->users->userExists($email)) {
            $response->getBody()->write('
                <script>
                    alert("Email already registered");
                    window.location.href = "/auth/register";
                </script>
            ');
            return $response;
        }

        // Create new user
        $userMapper = $this->users->getMapper();
        $userMapper->firstName = $firstName;
        $userMapper->lastName = $lastName;
        $userMapper->email = $email;
        $userMapper->password = password_hash($password, PASSWORD_DEFAULT);

        // Save user to database
        EntityManager::getManager()->flushMapper('user');

        // Set session
        $_SESSION['user_id'] = $userMapper->getId();
        $_SESSION['user_email'] = $userMapper->getEmail();
        $_SESSION['user_name'] = $userMapper->getFullName();

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
}