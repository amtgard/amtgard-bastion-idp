<?php

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Repositories\AccessTokenRepository;
use Amtgard\IdP\Utility\Utility;
use Couchbase\User;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Amtgard\IdP\Services\OrkService;
use DateTime;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

class ResourcesController
{
    private TwigEnvironment $twig;
    protected LoggerInterface $logger;
    private ClientRepositoryInterface $clientRepository;

    private Database $database;
    private OrkService $orkService;
    private UserOrkProfileRepository $orkProfileRepository;
    private UserClientAuthorizationRepository $userClientAuthorizationRepository;
    private UserLoginRepository $userLoginRepository;

    public function __construct(
        LoggerInterface $logger,
        TwigEnvironment $twig,
        ClientRepositoryInterface $clientRepository,
        Database $database,
        OrkService $orkService,
        UserOrkProfileRepository $orkProfileRepository,
        UserClientAuthorizationRepository $userClientAuthorizationRepository,
        UserLoginRepository $userLoginRepository
    ) {
        $this->logger = $logger;
        $this->twig = $twig;
        $this->clientRepository = $clientRepository;
        $this->database = $database;
        $this->orkService = $orkService;
        $this->orkProfileRepository = $orkProfileRepository;
        $this->userClientAuthorizationRepository = $userClientAuthorizationRepository;
        $this->userLoginRepository = $userLoginRepository;
    }

    public function userinfo(Request $request, Response $response): Response
    {
        $user = Utility::getAuthenticatedUser();
        if (!$user) {
            return $response->withStatus(401);
        }

        $userData = [
            'id' => $user->getUserId(),
            'email' => $user->getEmail()
        ];

        $orkProfile = $this->orkProfileRepository->findByUserId($user->getId());
        if ($orkProfile) {
            $userData['ork_profile'] = [
                'mundane_id' => $orkProfile->getMundaneId(),
                'username' => $orkProfile->getUsername(),
                'persona' => $orkProfile->getPersona(),
                'suspended' => (bool) $orkProfile->getSuspended(),
                'suspended_at' => $orkProfile->getSuspendedAt()?->format('Y-m-d'),
                'suspended_until' => $orkProfile->getSuspendedUntil()?->format('Y-m-d'),
                'park_id' => $orkProfile->getParkId(),
                'park_name' => $orkProfile->getParkName(),
                'kingdom_id' => $orkProfile->getKingdomId(),
                'kingdom_name' => $orkProfile->getKingdomName(),
                'image' => $orkProfile->getImage(),
                'heraldry' => $orkProfile->getHeraldry(),
                'dues_through' => $orkProfile->getDuesThrough()?->format('Y-m-d')
            ];
        }

        $response->getBody()->write(json_encode($userData));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function authorizations(Request $request, Response $response): Response
    {
        $user = Utility::getAuthenticatedUser();
        if (!$user) {
            return $response->withStatus(401);
        }

        $clients = $this->clientRepository->findActiveClientsForUser($user->getId());

        $response->getBody()->write(json_encode(array_values($clients)));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Display the profile page.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function profile(Request $request, Response $response): Response
    {
        $avatarUrl = $_SESSION['avatar_url'] ?? null;
        $params = $request->getQueryParams();
        $error = $params['error'] ?? null;
        $success = $params['success'] ?? null;

        $user = Utility::getAuthenticatedUser();

        $orkProfile = null;
        $userLogins = [];
        if ($user) {
            $clients = $this->clientRepository->findActiveClientsForUser($user->getId());
            $orkProfile = $this->orkProfileRepository->findByUserId($user->getId());
            $userLogins = $this->userLoginRepository->getAllLoginsForUser($user->getId());
        }

        $response->getBody()->write($this->twig->render('profile.twig', [
            'avatarUrl' => $avatarUrl,
            'userLogins' => $userLogins,
            'authorizations' => array_values($clients ?? []),
            'orkProfile' => $orkProfile,
            'error' => $error,
            'success' => $success
        ]));

        return $response;
    }


    public function linkOrkAccount(Request $request, Response $response): Response
    {
        $params = (array) $request->getParsedBody();
        $username = $params['username'] ?? '';
        $password = $params['password'] ?? '';

        $user = Utility::getAuthenticatedUser();
        if (!$user) {
            return $response->withHeader('Location', '/auth/login')->withStatus(302);
        }

        $authData = $this->orkService->authorize($username, $password);
        if (!$authData) {
            $this->logger->warning('LinkORK: Authorization failed', ['username' => $username]);
            return $response->withHeader('Location', '/resources/profile?error=ork_auth_failed')->withStatus(302);
        }

        $token = $authData['Token'];
        $mundaneId = $authData['UserId'];

        $playerData = $this->orkService->getPlayer($token, $mundaneId);

        if (!$playerData) {
            return $response->withHeader('Location', '/resources/profile?error=ork_player_failed')->withStatus(302);
        }

        $parkData = $this->orkService->getParkShortInfo((int) $playerData['ParkId']);

        $this->orkProfileRepository->saveOrUpdateProfile($playerData, $parkData, $token, $user->getId());

        return $response->withHeader('Location', '/resources/profile?success=linked')->withStatus(302);
    }

    public function refreshOrkAccount(Request $request, Response $response): Response
    {
        $user = Utility::getAuthenticatedUser();
        if (!$user) {
            return $response->withHeader('Location', '/auth/login')->withStatus(302);
        }

        $existing = $this->orkProfileRepository->findByUserId($user->getId());
        if (!$existing) {
            return $response->withHeader('Location', '/resources/profile?error=no_profile')->withStatus(302);
        }

        $token = $existing->getOrkToken();
        $mundaneId = $existing->getMundaneId();

        $playerData = $this->orkService->getPlayer($token, $mundaneId);

        if (!$playerData) {
            $this->logger->warning('RefreshORK: Player fetch failed', ['userId' => $user->getId()]);
            return $response->withHeader('Location', '/resources/profile?error=ork_refresh_failed')->withStatus(302);
        }

        $parkData = $this->orkService->getParkShortInfo((int) $playerData['ParkId']);

        $this->orkProfileRepository->saveOrUpdateProfile($playerData, $parkData, $token, $user->getId());

        return $response->withHeader('Location', '/resources/profile?success=refreshed')->withStatus(302);
    }

    public function revokeAuthorization(Request $request, Response $response): Response
    {
        /** @var UserEntity $user */
        $user = Utility::getAuthenticatedUser();
        if (!$user) {
            return $response->withHeader('Location', '/auth/login')->withStatus(302);
        }

        $params = (array) $request->getParsedBody();
        $clientId = isset($params['client_id']) ? (int) $params['client_id'] : 0;

        if ($clientId <= 0) {
            return $response->withHeader('Location', '/resources/profile?error=invalid_client')->withStatus(302);
        }

        // We use the email/username as the identifier for authorization
        $this->userClientAuthorizationRepository->revokeAuthorization($user->getUserId(), $clientId);

        // Also revoke access tokens for this client/user combo if needed, 
        // but for now removing the authorization record prevents future token issuance.
        // Implementing full token revocation would require AccessTokenRepository method.

        return $response->withHeader('Location', '/resources/profile?success=revoked')->withStatus(302);
    }
}