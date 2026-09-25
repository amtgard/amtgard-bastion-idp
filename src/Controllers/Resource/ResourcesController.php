<?php

declare(strict_types=1);


namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Amtgard\IdP\Services\IdpEmailMigrationService;
use Amtgard\IdP\Services\Mailbox\MailboxChallengePurpose;
use Amtgard\IdP\Services\MailboxChallengeService;
use Amtgard\IdP\Services\OrkLinkTokenService;
use Amtgard\IdP\Services\OrkService;
use Amtgard\IdP\Services\ResourcesUserinfoService;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\IdP\Utility\Security\CurrentUserResolverInterface;
use Amtgard\IdP\Utility\UserAuthority;
use Amtgard\SetQueue\PubSubQueue;
use Amtgard\IdP\Utility\Security\RedirectValidator;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use OpenApi\Attributes as OA;
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
    private PubSubQueue $redisPubSubQueue;
    private PubSubQueueHandle $pubSubQueueHandle;
    private OrkService $orkService;
    private UserOrkProfileRepository $orkProfileRepository;
    private UserRepository $userRepository;
    private UserClientAuthorizationRepository $userClientAuthorizationRepository;
    private UserLoginRepository $userLoginRepository;
    private AmtgardIdpJwt $amtgardIdpJwt;
    private UserAuthority $userAuthority;
    private CurrentUserResolverInterface $currentUserResolver;
    private ResourcesUserinfoService $userinfoService;
    private ClientAccessRepository $clientAccessRepository;
    private MailboxChallengeService $mailboxChallenges;
    private OrkLinkTokenService $orkLinkTokenService;
    private IdpEmailMigrationService $emailMigration;


    public function __construct(
        LoggerInterface $logger,
        TwigEnvironment $twig,
        ClientRepositoryInterface $clientRepository,
        PubSubQueue $redisPubSubQueue,
        PubSubQueueHandle $pubSubQueueHandle,
        Database $database,
        OrkService $orkService,
        UserOrkProfileRepository $orkProfileRepository,
        UserRepository $userRepository,
        UserClientAuthorizationRepository $userClientAuthorizationRepository,
        UserLoginRepository $userLoginRepository,
        AmtgardIdpJwt $amtgardIdpJwt,
        UserAuthority $userAuthority,
        CurrentUserResolverInterface $currentUserResolver,
        ResourcesUserinfoService $userinfoService,
        ClientAccessRepository $clientAccessRepository,
        MailboxChallengeService $mailboxChallenges,
        OrkLinkTokenService $orkLinkTokenService,
        IdpEmailMigrationService $emailMigration,
    ) {
        $this->logger = $logger;
        $this->twig = $twig;
        $this->clientRepository = $clientRepository;
        $this->database = $database;
        $this->redisPubSubQueue = $redisPubSubQueue;
        $this->pubSubQueueHandle = $pubSubQueueHandle;
        $this->orkService = $orkService;
        $this->orkProfileRepository = $orkProfileRepository;
        $this->userRepository = $userRepository;
        $this->userClientAuthorizationRepository = $userClientAuthorizationRepository;
        $this->userLoginRepository = $userLoginRepository;
        $this->amtgardIdpJwt = $amtgardIdpJwt;
        $this->userAuthority = $userAuthority;
        $this->currentUserResolver = $currentUserResolver;
        $this->clientAccessRepository = $clientAccessRepository;
        $this->userinfoService = $userinfoService;
        $this->mailboxChallenges = $mailboxChallenges;
        $this->orkLinkTokenService = $orkLinkTokenService;
        $this->emailMigration = $emailMigration;
    }

    #[OA\Get(
        path: '/resources/jwt',
        operationId: 'getJwt',
        summary: 'Elevate to an authorization JWT',
        description: 'Remint well: exchange an OAuth access token (or browser session) for a signed RS256 authorization JWT containing IAM policy and optional client_metadata, plus a compact heartbeat JWT (sub, aud, iss, exp, pvh). Does not accept authorization JWTs. Use jwt or compact_jwt as Bearer on GET /resources/userinfo and GET /resources/validate.',
        security: [
            ['oauthAccessToken' => []],
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'JWT response',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        properties: [
                            new OA\Property(property: 'jwt', type: 'string', description: 'Fat RS256 authorization JWT (policy, identity, pvh). Integrator default.'),
                            new OA\Property(property: 'compact_jwt', type: 'string', description: 'Compact RS256 heartbeat JWT (sub, aud, iss, exp, pvh). Same keys and exp as jwt.'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    public function getJwt(Request $request, Response $response): Response
    {
        $user = $this->currentUserResolver->resolve();
        if (!$user) {
            return $response->withStatus(401);
        }

        $tokens = $this->amtgardIdpJwt->buildAuthorizationTokens($user);

        $response->getBody()->write(json_encode($tokens));
        return $response->withHeader('Content-Type', 'application/json');
    }

    #[OA\Get(
        path: '/resources/userinfo',
        operationId: 'userinfo',
        summary: 'Get user information',
        description: 'Accepts an RS256 authorization JWT (pvh/policy) or a League OAuth access token. Authorization JWT cache miss seeds Redis for that aud; one generation behind is 409 stale_token. OAuth access tokens are validated by the resource server. Does not remint.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User information response',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', description: 'IDP user UUID'),
                            new OA\Property(property: 'email', type: 'string'),
                            new OA\Property(
                                property: 'ork_profile',
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'mundane_id', type: 'integer'),
                                    new OA\Property(property: 'username', type: 'string'),
                                    new OA\Property(property: 'persona', type: 'string'),
                                    new OA\Property(property: 'suspended', type: 'boolean'),
                                    new OA\Property(property: 'suspended_at', type: 'string', format: 'date'),
                                    new OA\Property(property: 'suspended_until', type: 'string', format: 'date'),
                                    new OA\Property(property: 'park_id', type: 'integer'),
                                    new OA\Property(property: 'park_name', type: 'string'),
                                    new OA\Property(property: 'kingdom_id', type: 'integer'),
                                    new OA\Property(property: 'kingdom_name', type: 'string'),
                                    new OA\Property(property: 'image', type: 'string'),
                                    new OA\Property(property: 'heraldry', type: 'string'),
                                    new OA\Property(property: 'dues_through', type: 'string', format: 'date'),
                                ]
                            ),
                        ]
                    )
                )
            ),
            new OA\Response(
                response: 409,
                description: 'Presented pvh is one generation behind',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'stale_token'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    public function userinfo(Request $request, Response $response): Response
    {
        $user = $this->currentUserResolver->resolve();
        if (!$user) {
            return $response->withStatus(401);
        }

        $response->getBody()->write(json_encode($this->userinfoService->buildPayload($user)));
        return $response->withHeader('Content-Type', 'application/json');
    }

    #[OA\Get(
        path: '/resources/authorizations',
        operationId: 'authorizations',
        summary: 'Get user authorizations',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User authorizations response',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'logo', type: 'string'),
                            ]
                        )
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    public function authorizations(Request $request, Response $response): Response
    {
        $user = $this->currentUserResolver->resolve();
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

        $user = $this->currentUserResolver->resolve();

        $orkProfile = null;
        $userLogins = [];
        $isAdmin = false;
        $hasClientAccess = false;
        $clients = [];
        if ($user) {
            $isAdmin = $this->userAuthority->isAdmin($user);
            $hasClientAccess = $this->clientAccessRepository->userHasAnyAccess($user->getId());
            $clients = $this->clientRepository->findActiveClientsForUser($user->getId());
            $orkProfile = $this->orkProfileRepository->findByUserId($user->getId());
            $userLogins = $this->userLoginRepository->getAllLoginsForUser($user->getId());
        }

        $pendingRedirect = RedirectValidator::sanitizeOrNull($_SESSION['redirect'] ?? null);

        $response->getBody()->write($this->twig->render('profile.twig', [
            'avatarUrl' => $avatarUrl,
            'userLogins' => $userLogins,
            'authorizations' => array_values($clients ?? []),
            'orkProfile' => $orkProfile,
            'error' => $error,
            'success' => $success,
            'isAdmin' => $isAdmin,
            'hasClientAccess' => $hasClientAccess,
            'pendingRedirect' => $pendingRedirect !== null,
            'sessionUserId' => $_SESSION['user_id'] ?? null,
        ]));

        return $response;
    }


    #[OA\Post(
        path: '/resources/profile/link-ork',
        operationId: 'linkOrkAccount',
        summary: 'Link the signed-in IDP user to ORK with username and password',
        description: 'Current ORK contract. The profile form posts ORK username and password. The IDP calls ORK authorize and stores the profile. Possession claims use POST /resources/profile/link-ork-code instead.',
        tags: ['ORK Integration'],
        security: [['idpSession' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['username', 'password', '_csrf_token'],
                    properties: [
                        new OA\Property(property: 'username', type: 'string'),
                        new OA\Property(property: 'password', type: 'string'),
                        new OA\Property(property: '_csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 302, description: 'Redirect to the profile, or to a pending OAuth URL after a successful link. Unauthenticated requests redirect to /auth/login.'),
        ]
    )]
    public function linkOrkAccount(Request $request, Response $response): Response
    {
        $params = (array) $request->getParsedBody();
        $username = $params['username'] ?? '';
        $password = $params['password'] ?? '';

        $user = $this->currentUserResolver->resolve();
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

        $parkData = $this->orkService->resolveParkDataFromPlayer($playerData, $user->getId(), 'LinkORK');

        $this->orkProfileRepository->saveOrUpdateProfile($playerData, $parkData, $token, $user->getId());

        $storedRedirect = RedirectValidator::sanitizeOrNull($_SESSION['redirect'] ?? null);
        if ($storedRedirect !== null) {
            unset($_SESSION['redirect']);
            $jwt = $this->amtgardIdpJwt->buildAuthorizationJwt($user);
            return $response->withHeader('Location', $storedRedirect . "?jwt=$jwt")->withStatus(302);
        }

        return $response->withHeader('Location', '/resources/profile?success=linked')->withStatus(302);
    }

    /**
     * Future possession claim. ORK mails a code to the mundane address.
     * Not used by the profile form until Login/claim_ork exists.
     */
    #[OA\Post(
        path: '/resources/profile/link-ork-code',
        operationId: 'startOrkCodeClaim',
        summary: 'Start an IDP-claims-ORK possession flow',
        description: 'Reserves a claim_ork challenge and redirects to ORK Login/claim_ork with an IDP-signed handoff JWT. Does not accept an ORK password. Unused until ORK implements that route.',
        tags: ['ORK Integration'],
        security: [['idpSession' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['username', '_csrf_token'],
                    properties: [
                        new OA\Property(property: 'username', type: 'string', description: 'ORK persona username. ORK mails the code to that mundane.'),
                        new OA\Property(property: '_csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 302, description: 'Redirect to ORK Login/claim_ork, back to the profile when username is empty, or to /auth/login when signed out.'),
        ]
    )]
    public function startOrkCodeClaim(Request $request, Response $response): Response
    {
        return Optional::ofNullable($this->currentUserResolver->resolve())
            ->map(function (UserEntity $user) use ($request, $response) {
                $username = trim((string) (((array) $request->getParsedBody())['username'] ?? ''));
                if ($username === '') {
                    return $response->withHeader('Location', '/resources/profile?error=ork_username_required')->withStatus(302);
                }

                $challenge = $this->mailboxChallenges->reserve(
                    MailboxChallengePurpose::CLAIM_ORK,
                    $user->getUserId(),
                );
                $jwt = $this->orkLinkTokenService->mintFlowAHandoff($user->getUserId(), $challenge->getId());
                $this->logger->info('mailbox.flow_a.started', [
                    'challenge_id' => $challenge->getId(),
                    'sent_to_hash' => $challenge->getSentToHash(),
                ]);

                return $response
                    ->withHeader('Location', $this->orkLinkTokenService->flowAClaimRedirectUrl($jwt, $username))
                    ->withStatus(302);
            })
            ->orElseGet(fn () => $response->withHeader('Location', '/auth/login')->withStatus(302));
    }

    #[OA\Get(
        path: '/auth/connect/complete',
        operationId: 'completeOrkClaim',
        summary: 'Finish an IDP-claims-ORK possession handoff',
        description: 'ORK redirects the signed-in browser here with a completion JWT (purpose claim_ork). The IDP links only when the JWT mundane id and idp user id match the consumed challenge row.',
        tags: ['ORK Integration'],
        security: [['idpSession' => []]],
        parameters: [
            new OA\QueryParameter(name: 't', required: true, schema: new OA\Schema(type: 'string'), description: 'ORK-signed completion JWT'),
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirect to the profile or a pending OAuth URL. Failures use a profile error query. Signed-out requests redirect to /auth/login.'),
        ]
    )]
    public function completeOrkClaim(Request $request, Response $response): Response
    {
        return Optional::ofNullable($this->currentUserResolver->resolve())
            ->map(fn (UserEntity $user) => $this->finishFlowA($user, $request, $response))
            ->orElseGet(fn () => $response->withHeader('Location', '/auth/login')->withStatus(302));
    }

    #[OA\Post(
        path: '/resources/profile/email/start',
        operationId: 'startEmailMigration',
        summary: 'Mail a code to the current IDP address',
        description: 'First step of an IDP email change. The code goes to the address already stored on the user, not to new_email.',
        tags: ['ORK Integration'],
        security: [['idpSession' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['new_email', '_csrf_token'],
                    properties: [
                        new OA\Property(property: 'new_email', type: 'string', format: 'email'),
                        new OA\Property(property: '_csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 302, description: 'Redirect to the profile. success=email_code_sent or error=email_invalid.'),
        ]
    )]
    public function startEmailMigration(Request $request, Response $response): Response
    {
        return Optional::ofNullable($this->currentUserResolver->resolve())
            ->map(function (UserEntity $user) use ($request, $response) {
                $newEmail = trim((string) (((array) $request->getParsedBody())['new_email'] ?? ''));
                try {
                    $issued = $this->emailMigration->start($user, $newEmail);
                    $_SESSION['email_migration_challenge_id'] = $issued->challengeId;
                } catch (\InvalidArgumentException) {
                    return $response->withHeader('Location', '/resources/profile?error=email_invalid')->withStatus(302);
                }

                return $response->withHeader('Location', '/resources/profile?success=email_code_sent')->withStatus(302);
            })
            ->orElseGet(fn () => $response->withHeader('Location', '/auth/login')->withStatus(302));
    }

    #[OA\Post(
        path: '/resources/profile/email/confirm',
        operationId: 'confirmEmailMigration',
        summary: 'Confirm the current-address code and mail the new address',
        tags: ['ORK Integration'],
        security: [['idpSession' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['code', '_csrf_token'],
                    properties: [
                        new OA\Property(property: 'code', type: 'string', description: '6-digit code sent to the current IDP email'),
                        new OA\Property(property: '_csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 302, description: 'Redirect to the profile. A valid code mails the proposed address.'),
        ]
    )]
    public function confirmEmailMigration(Request $request, Response $response): Response
    {
        return Optional::ofNullable($this->currentUserResolver->resolve())
            ->map(function (UserEntity $user) use ($request, $response) {
                $code = trim((string) (((array) $request->getParsedBody())['code'] ?? ''));
                $result = $this->emailMigration->confirm($user, $code);
                $location = $result->ok()
                    ? '/resources/profile?success=email_code_sent'
                    : '/resources/profile?error=email_code_failed';

                return $response->withHeader('Location', $location)->withStatus(302);
            })
            ->orElseGet(fn () => $response->withHeader('Location', '/auth/login')->withStatus(302));
    }

    #[OA\Post(
        path: '/resources/profile/email/commit',
        operationId: 'commitEmailMigration',
        summary: 'Confirm the new-address code and save the IDP email',
        tags: ['ORK Integration'],
        security: [['idpSession' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['code', '_csrf_token'],
                    properties: [
                        new OA\Property(property: 'code', type: 'string', description: '6-digit code sent to the proposed IDP email'),
                        new OA\Property(property: '_csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 302, description: 'Redirect to the profile. users.email changes only after this code succeeds.'),
        ]
    )]
    public function commitEmailMigration(Request $request, Response $response): Response
    {
        return Optional::ofNullable($this->currentUserResolver->resolve())
            ->map(function (UserEntity $user) use ($request, $response) {
                $code = trim((string) (((array) $request->getParsedBody())['code'] ?? ''));
                $result = $this->emailMigration->commit($user, $code);
                $location = $result->ok()
                    ? '/resources/profile?success=email_updated'
                    : '/resources/profile?error=email_code_failed';

                return $response->withHeader('Location', $location)->withStatus(302);
            })
            ->orElseGet(fn () => $response->withHeader('Location', '/auth/login')->withStatus(302));
    }

    public function refreshOrkAccount(Request $request, Response $response): Response
    {
        $user = $this->currentUserResolver->resolve();
        if (!$user) {
            return $response->withHeader('Location', '/auth/login')->withStatus(302);
        }

        $existing = $this->orkProfileRepository->findByUserId($user->getId());
        if (!$existing) {
            return $response->withHeader('Location', '/resources/profile?error=no_profile')->withStatus(302);
        }

        $token = $existing->getOrkToken();
        $mundaneId = $existing->getMundaneId();

        $this->logger->info('RefreshORK: starting refresh', [
            'userId' => $user->getId(),
            'mundaneId' => $mundaneId,
            'storedParkId' => $existing->getParkId(),
            'storedParkName' => $existing->getParkName(),
        ]);

        $playerData = $this->orkService->getPlayer($token, $mundaneId);

        if (!$playerData) {
            $this->logger->warning('RefreshORK: Player fetch failed', ['userId' => $user->getId()]);
            return $response->withHeader('Location', '/resources/profile?error=ork_refresh_failed')->withStatus(302);
        }

        $parkData = $this->orkService->resolveParkDataFromPlayer($playerData, $user->getId(), 'RefreshORK');

        $this->orkProfileRepository->saveOrUpdateProfile($playerData, $parkData, $token, $user->getId());

        $this->logger->info('RefreshORK: profile saved', [
            'userId' => $user->getId(),
            'mundaneId' => $mundaneId,
            'parkDataResolved' => $parkData !== null,
        ]);

        return $response->withHeader('Location', '/resources/profile?success=refreshed')->withStatus(302);
    }

    /**
     * Server-to-server endpoint called by ORK to mirror a successful ORK-side
     * link-write back into the IDP. Behind ConfidentialClientBasicAuthMiddleware
     * so only the configured ORK confidential client can invoke it.
     *
     * Request:  { "idp_user_id": "<uuid string>", "mundane_id": 12345 }
     * Optional challenge_id must already be consumed. Omitting it keeps the current ORK mirror.
     * Response: 204 on success, 400/404/409 on failure (idempotent).
     */
    #[OA\Post(
        path: '/resources/link-ork-profile',
        operationId: 'linkOrkProfile',
        summary: 'Mirror an ORK account link into the IDP (ORK server-to-server)',
        description: 'ORK server-to-server mirror. `{idp_user_id, mundane_id}` is the current contract. When `challenge_id` is sent it must be a consumed mailbox challenge. Restricted to confidential clients listed in `LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS`.',
        tags: ['ORK Integration'],
        security: [['orkConfidentialClient' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['idp_user_id', 'mundane_id'],
                    properties: [
                        new OA\Property(property: 'idp_user_id', type: 'string', format: 'uuid', description: 'IDP user UUID'),
                        new OA\Property(property: 'mundane_id', type: 'integer', minimum: 1, description: 'ORK mundane player ID'),
                        new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid', description: 'Optional. When present, must be a consumed mailbox challenge. Omitted by the current ORK mirror.'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Link recorded (idempotent)'),
            new OA\Response(
                response: 400,
                description: 'Invalid request body',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Unknown idp_user_id',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 409,
                description: 'idp_user_id already linked to a different mundane_id',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
        ]
    )]
    public function linkOrkProfile(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $idpUserId = Optional::ofNullable($body['idp_user_id'] ?? null)
            ->map(fn($v) => trim((string)$v))
            ->filter(fn($v) => $v !== '')
            ->orElse(null);
        $mundaneId = Optional::ofNullable($body['mundane_id'] ?? null)
            ->map(fn($v) => (int)$v)
            ->filter(fn($v) => $v > 0)
            ->orElse(null);
        $challengeId = Optional::ofNullable($body['challenge_id'] ?? null)
            ->map(fn($v) => trim((string)$v))
            ->filter(fn($v) => $v !== '')
            ->orElse(null);

        $completeBody = Optional::ofNullable($idpUserId)
            ->filter(fn () => Optional::ofNullable($mundaneId)->isPresent());
        if (!$completeBody->isPresent()) {
            $response->getBody()->write(json_encode(['error' => 'idp_user_id (string) and mundane_id (positive int) are required']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $challengeReady = Optional::ofNullable($challengeId)
            ->map(fn (string $id) => $this->mailboxChallenges->isConsumedForUser($id, $idpUserId))
            ->orElse(true);
        if (!$challengeReady) {
            $this->logger->info('linkOrkProfile rejected challenge', [
                'challenge_id' => $challengeId,
                'idp_user_id' => $idpUserId,
            ]);
            $response->getBody()->write(json_encode(['error' => 'challenge_id is missing, unknown, expired, unconsumed, or for another user']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $userOpt = Optional::ofNullable($this->userRepository->findUserByUserId($idpUserId));
        if (!$userOpt->isPresent()) {
            $this->logger->info('linkOrkProfile unknown idp_user_id', ['idp_user_id' => $idpUserId]);
            $response->getBody()->write(json_encode(['error' => 'unknown idp_user_id']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
        $user = $userOpt->get();

        try {
            $this->orkProfileRepository->linkExistingUserToMundane($user->getId(), $mundaneId, 'mirror');
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'conflict')) {
                $this->logger->warning('linkOrkProfile conflict', [
                    'idp_user_id' => $idpUserId,
                    'requested_mundane_id' => $mundaneId,
                    'msg' => $e->getMessage(),
                ]);
                $response->getBody()->write(json_encode(['error' => 'idp_user_id already linked to a different mundane_id']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
            }
            throw $e;
        }

        $this->logger->info('linkOrkProfile success', ['idp_user_id' => $idpUserId, 'mundane_id' => $mundaneId]);
        return $response->withStatus(204);
    }

    private function finishFlowA(UserEntity $user, Request $request, Response $response): Response
    {
        $jwt = (string) (($request->getQueryParams()['t'] ?? ''));

        return Optional::ofNullable($this->orkLinkTokenService->peekFlowACompletion($jwt))
            ->filter(fn (array $claims) => $claims['idp_user_id'] === $user->getUserId())
            ->map(function (array $claims) use ($user, $response) {
                return Optional::ofNullable($this->mailboxChallenges->findById($claims['challenge_id']))
                    ->filter(fn ($row) => $row->getPurpose() === MailboxChallengePurpose::CLAIM_ORK)
                    ->filter(fn ($row) => $row->getIdpUserId() === $user->getUserId())
                    ->filter(fn ($row) => !Optional::ofNullable($row->getConsumedAt())->isPresent())
                    ->filter(fn ($row) => $row->getExpiresAt() >= new \DateTime())
                    ->map(function ($row) use ($claims, $user, $response) {
                        try {
                            $consumedJti = $this->orkLinkTokenService->consumeJti($claims['jti']);
                        } catch (\Throwable $e) {
                            $this->logger->error('completeOrkClaim consumeJti failed', [
                                'challenge_id' => $row->getId(),
                                'msg' => $e->getMessage(),
                            ]);

                            return $response->withHeader('Location', '/resources/profile?error=ork_complete_failed')->withStatus(302);
                        }
                        if (!$consumedJti) {
                            return $response->withHeader('Location', '/resources/profile?error=ork_complete_replay')->withStatus(302);
                        }

                        try {
                            $this->orkProfileRepository->linkExistingUserToMundane($user->getId(), $claims['mundane_id'], 'ork_handoff');
                        } catch (\RuntimeException $e) {
                            if (str_contains($e->getMessage(), 'conflict')) {
                                $this->logger->warning('completeOrkClaim conflict', [
                                    'challenge_id' => $row->getId(),
                                    'msg' => $e->getMessage(),
                                ]);

                                return $response->withHeader('Location', '/resources/profile?error=ork_link_conflict')->withStatus(302);
                            }
                            throw $e;
                        }

                        $this->mailboxChallenges->consume($row->getId());
                        $this->logger->info('mailbox.flow_a.completed', [
                            'challenge_id' => $row->getId(),
                            'sent_to_hash' => $row->getSentToHash(),
                        ]);

                        $storedRedirect = RedirectValidator::sanitizeOrNull($_SESSION['redirect'] ?? null);
                        return Optional::ofNullable($storedRedirect)
                            ->map(function (string $redirect) use ($user, $response) {
                                unset($_SESSION['redirect']);
                                $jwt = $this->amtgardIdpJwt->buildAuthorizationJwt($user);

                                return $response->withHeader('Location', $redirect . "?jwt=$jwt")->withStatus(302);
                            })
                            ->orElseGet(fn () => $response->withHeader('Location', '/resources/profile?success=linked')->withStatus(302));
                    })
                    ->orElseGet(fn () => $response->withHeader('Location', '/resources/profile?error=ork_complete_failed')->withStatus(302));
            })
            ->orElseGet(fn () => $response->withHeader('Location', '/resources/profile?error=ork_complete_failed')->withStatus(302));
    }

    public function revokeAuthorization(Request $request, Response $response): Response
    {
        /** @var UserEntity $user */
        $user = $this->currentUserResolver->resolve();
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