<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Client;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Controllers\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Entities\UserLoginEntity;
use Amtgard\IdP\Persistence\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Repositories\UserRepository;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;
use Twig\Environment as TwigEnvironment;

class AuthController extends BaseAuthController
{
    private UserRepository $users;
    private UserLoginRepository $logins;
    private Google $googleProvider;
    private Facebook $facebookProvider;
    private TwigEnvironment $twig;

    public function __construct(
        EntityManager $entityManager,
        UserRepository $users,
        UserLoginRepository $logins,
        LoggerInterface $logger,
        Google          $googleProvider,
        Facebook        $facebookProvider,
        AmtgardIdpJwt   $amtgardIdpJwt,
        TwigEnvironment $twig
    )
    {
        parent::__construct($logger, $amtgardIdpJwt);
        $this->users = $users;
        $this->logins = $logins;
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

        $user = $this->users->getUserByEmail($email);
        $login = Optional::ofNullable($this->users->getUserByEmail($email))
            ->map(function ($user) {
                return $this->logins->getLoginByUser($user);
            })->orElse(null);

        // Check if user exists and password is correct
        if ($login === null || $login->getPassword() === null || !password_verify($password, $login->getPassword())) {
            // Invalid credentials, redirect back to login form
            $response->getBody()->write('
                <script>
                    alert("Invalid email or password");
                    window.location.href = "/auth/login";
                </script>
            ');
            return $response;
        }

        // Set session
        return $this->finalizeAuthorization($login, $request, $response);
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
        $user = $this->users->createLocalUser($email, $firstName, $lastName);
        $login = $this->logins->createLocalLogin($user, $password);

        return $this->finalizeAuthorization($login, $request, $response);
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