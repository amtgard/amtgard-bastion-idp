<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Client;

use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Services\Mailbox\MailboxChallengePurpose;
use Amtgard\IdP\Services\MailboxChallengeService;
use Amtgard\IdP\Services\OrkLinkTokenService;
use Amtgard\IdP\Services\RegistrationService;
use OpenApi\Attributes as OA;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * ORK→IDP handoff.
 *
 * A JWT without `challenge_id` is the current ORK contract: login or register
 * on POST /auth/connect/login and /auth/connect/register.
 * A JWT with `challenge_id` is the future possession contract: mail a code,
 * then POST /auth/connect/code.
 */
final class ConnectController
{
    public function __construct(
        private TwigEnvironment $twig,
        private UserRepository $users,
        private UserLoginRepository $logins,
        private UserOrkProfileRepository $orkProfileRepository,
        private OrkLinkTokenService $tokenService,
        private RegistrationService $registrationService,
        private MailboxChallengeService $challenges,
        private LoggerInterface $logger,
    ) {
    }

    #[OA\Get(
        path: '/auth/connect',
        operationId: 'showConnect',
        summary: 'ORK handoff page',
        description: 'A JWT without challenge_id renders login/register (current ORK). A JWT with challenge_id and idp_email renders the possession code form and mails that hint. The JWT email is not a bind key.',
        tags: ['ORK Integration'],
        parameters: [
            new OA\QueryParameter(name: 'link_token', required: true, schema: new OA\Schema(type: 'string'), description: 'ORK-signed handoff JWT (iss=ork, aud=idp)'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Login/register form or possession code form'),
            new OA\Response(response: 400, description: 'Missing or invalid link_token'),
        ]
    )]
    public function showConnect(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $linkToken = (string) ($params['link_token'] ?? '');
        if ($linkToken === '') {
            return $this->renderError($response, 'This link is invalid. Return to ORK and start over.');
        }

        return Optional::ofNullable($this->tokenService->peekClaims($linkToken))
            ->map(fn (array $claims) => $this->showCodeConnect($response, $linkToken, $claims))
            ->orElseGet(fn () => $this->showLegacyConnect($response, $linkToken, (string) ($params['email'] ?? '')));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function showCodeConnect(Response $response, string $linkToken, array $claims): Response
    {
                $destination = $this->destinationForHint($claims['idp_email']);
                $sentToHash = hash('sha256', $destination);
                $challengeId = Optional::ofNullable(
                    $this->challenges->findOpenByMundanePurposeAndHash(
                        $claims['mundane_id'],
                        MailboxChallengePurpose::CLAIM_IDP,
                        $sentToHash,
                    )
                )
                    ->map(fn (MailboxChallengeEntity $row) => $row->getId())
                    ->orElseGet(function () use ($claims, $destination) {
                        $existing = $this->users->getUserByEmail($destination);
                        $issued = $this->challenges->issue(
                            MailboxChallengePurpose::CLAIM_IDP,
                            $destination,
                            Optional::ofNullable($existing)->map(fn (UserEntity $user) => $user->getUserId())->orElse(null),
                            $claims['mundane_id'],
                        );

                        return $issued->challengeId;
                    });

        $response->getBody()->write($this->twig->render('connect.twig', [
            'handoff' => 'code',
            'link_token' => $linkToken,
            'challenge_id' => $challengeId,
            'needs_password' => false,
            'email' => '',
            'defaultTab' => 'login',
            'error' => null,
            'notice' => 'If that mailbox can receive mail, a code was sent. The ORK and Amtgard addresses do not need to match.',
        ]));

        return $response;
    }

    private function showLegacyConnect(Response $response, string $linkToken, string $email): Response
    {
        return Optional::ofNullable($this->tokenService->peekLegacyClaims($linkToken))
            ->map(function (array $peek) use ($response, $linkToken, $email) {
                $emailFromToken = $peek['email'] !== '' ? $peek['email'] : $email;
                $defaultTab = $this->users->userExists($emailFromToken) ? 'login' : 'register';
                $response->getBody()->write($this->twig->render('connect.twig', [
                    'handoff' => 'legacy',
                    'link_token' => $linkToken,
                    'email' => $emailFromToken,
                    'defaultTab' => $defaultTab,
                    'challenge_id' => '',
                    'needs_password' => false,
                    'error' => null,
                    'notice' => null,
                ]));

                return $response;
            })
            ->orElseGet(fn () => $this->renderError($response, 'This link is invalid or expired. Return to ORK and start over.'));
    }

    #[OA\Post(
        path: '/auth/connect/login',
        operationId: 'submitConnectLogin',
        summary: 'Current ORK handoff: log in and link',
        description: 'Password check against the email in a legacy handoff JWT (no challenge_id). Success redirects to ORK Login/idp_link_complete with a completion JWT that has no challenge_id.',
        tags: ['ORK Integration'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['link_token', 'password', '_csrf_token'],
                    properties: [
                        new OA\Property(property: 'link_token', type: 'string'),
                        new OA\Property(property: 'password', type: 'string'),
                        new OA\Property(property: '_csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 302, description: 'Redirect to ORK idp_link_complete'),
            new OA\Response(response: 200, description: 'Form re-rendered after a bad password'),
            new OA\Response(response: 400, description: 'Invalid or reused link_token'),
        ]
    )]
    public function submitConnectLogin(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $linkToken = (string) ($body['link_token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        return Optional::ofNullable($this->tokenService->peekLegacyClaims($linkToken))
            ->map(function (array $claims) use ($response, $linkToken, $password) {
                $authoritativeEmail = $claims['email'];
                $user = $this->users->getUserByEmail($authoritativeEmail);
                $passwordMatches = Optional::ofNullable($user)
                    ->map(fn (UserEntity $account) => $this->logins->getLoginByUser($account))
                    ->map(fn ($localLogin) => $localLogin->getPassword())
                    ->filter(fn ($hash) => is_string($hash) && $hash !== '' && password_verify($password, $hash))
                    ->isPresent();
                if (!$passwordMatches || $user === null) {
                    return $this->renderLegacyForm($response, $linkToken, $authoritativeEmail, 'login', 'Email or password incorrect.');
                }

                return $this->finishLegacyLink($response, $user, $claims);
            })
            ->orElseGet(fn () => $this->renderError($response, 'This link is invalid or expired. Return to ORK to get a fresh one.'));
    }

    #[OA\Post(
        path: '/auth/connect/register',
        operationId: 'submitConnectRegister',
        summary: 'Current ORK handoff: register and link',
        description: 'Creates the IDP user at the legacy JWT email, then links that user to the mundane in sub. The form email is ignored.',
        tags: ['ORK Integration'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['link_token', 'password', 'confirmPassword', '_csrf_token'],
                    properties: [
                        new OA\Property(property: 'link_token', type: 'string'),
                        new OA\Property(property: 'firstName', type: 'string'),
                        new OA\Property(property: 'lastName', type: 'string'),
                        new OA\Property(property: 'password', type: 'string'),
                        new OA\Property(property: 'confirmPassword', type: 'string'),
                        new OA\Property(property: '_csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 302, description: 'Redirect to ORK idp_link_complete'),
            new OA\Response(response: 200, description: 'Form re-rendered when passwords differ or registration fails'),
            new OA\Response(response: 400, description: 'Invalid or reused link_token'),
        ]
    )]
    public function submitConnectRegister(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $linkToken = (string) ($body['link_token'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $confirm = (string) ($body['confirmPassword'] ?? '');

        return Optional::ofNullable($this->tokenService->peekLegacyClaims($linkToken))
            ->map(function (array $claims) use ($response, $linkToken, $password, $confirm, $body) {
                if ($password !== $confirm) {
                    return $this->renderLegacyForm($response, $linkToken, $claims['email'], 'register', 'Passwords do not match.');
                }

                $result = $this->registrationService->register(
                    (string) ($body['firstName'] ?? ''),
                    (string) ($body['lastName'] ?? ''),
                    $claims['email'],
                    $password,
                );
                if (!$result['ok']) {
                    return $this->renderLegacyForm($response, $linkToken, $claims['email'], 'register', $result['error']);
                }

                return $this->finishLegacyLink($response, $result['user'], $claims);
            })
            ->orElseGet(fn () => $this->renderError($response, 'This link is invalid or expired. Return to ORK to get a fresh one.'));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function finishLegacyLink(Response $response, UserEntity $user, array $claims): Response
    {
        try {
            $consumed = $this->tokenService->consumeJti($claims['jti']);
        } catch (\Throwable $e) {
            $this->logger->error('ConnectController login: consumeJti failed', [
                'mundane_id' => $claims['mundane_id'],
                'msg' => $e->getMessage(),
            ]);

            return $this->renderError($response, 'Something went wrong on our end. Your link is still valid — please try again.');
        }
        if (!$consumed) {
            return $this->renderError($response, 'This link has already been used. Return to ORK to get a fresh one.');
        }

        try {
            $this->orkProfileRepository->linkExistingUserToMundane($user->getId(), $claims['mundane_id'], 'ork_handoff');
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'conflict')) {
                $this->logger->warning('ConnectController login link conflict', ['msg' => $e->getMessage()]);

                return $this->renderError($response, 'That ORK profile is already linked to a different Amtgard account.');
            }
            $this->logger->error('ConnectController login: link write failed after jti consumed', [
                'idp_user_id' => $user->getUserId(),
                'mundane_id' => $claims['mundane_id'],
                'msg' => $e->getMessage(),
            ]);

            return $this->renderError($response, 'Something went wrong on our end. Please try again or return to ORK to get a fresh link.');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (!session_regenerate_id(true)) {
            $this->logger->warning('session_regenerate_id failed during connect handoff', ['user_id' => $user->getUserId()]);
        }
        $_SESSION['user_id'] = $user->getUserId();

        if (!$this->tokenService->hasSharedSecret()) {
            return $response->withHeader('Location', $this->tokenService->orkBaseUrl() . '/')->withStatus(302);
        }

        $jwt = $this->tokenService->mintLegacyCompletion($user->getUserId(), $claims['mundane_id']);

        return $response->withHeader('Location', $this->tokenService->flowBCompletionRedirectUrl($jwt))->withStatus(302);
    }

    #[OA\Post(
        path: '/auth/connect/code',
        operationId: 'submitConnectCode',
        summary: 'Possession handoff: submit the mailbox code',
        description: 'Used when the handoff JWT includes challenge_id. The code was mailed to the IDP address (or the registration hint). Unknown addresses are created only after the code and a password succeed on this request.',
        tags: ['ORK Integration'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['link_token', 'challenge_id', 'code', '_csrf_token'],
                    properties: [
                        new OA\Property(property: 'link_token', type: 'string'),
                        new OA\Property(property: 'challenge_id', type: 'string'),
                        new OA\Property(property: 'code', type: 'string'),
                        new OA\Property(property: 'password', type: 'string', description: 'Required only when the destination has no IDP user yet'),
                        new OA\Property(property: 'confirmPassword', type: 'string'),
                        new OA\Property(property: 'firstName', type: 'string'),
                        new OA\Property(property: 'lastName', type: 'string'),
                        new OA\Property(property: '_csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 302, description: 'Redirect to ORK idp_link_complete with a completion JWT that includes challenge_id'),
            new OA\Response(response: 200, description: 'Code form re-rendered, or a password form when the address is new'),
            new OA\Response(response: 400, description: 'Invalid handoff JWT'),
        ]
    )]
    public function submitConnectCode(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $linkToken = (string) ($body['link_token'] ?? '');
        $challengeId = (string) ($body['challenge_id'] ?? '');
        $code = trim((string) ($body['code'] ?? ''));

        return Optional::ofNullable($this->tokenService->peekClaims($linkToken))
            ->map(function (array $claims) use ($response, $linkToken, $challengeId, $code, $body) {
                $checked = $this->challenges->check($challengeId, $code);
                if (!$checked->ok()) {
                    return $this->renderForm($response, $linkToken, $challengeId, false, 'That code did not work. Try again.');
                }

                $destination = $this->destinationForHint($claims['idp_email']);
                $user = $this->users->getUserByEmail($destination);

                return Optional::ofNullable($user)
                    ->map(fn (UserEntity $existing) => $this->finishLink($response, $existing, $claims, $challengeId, false))
                    ->orElseGet(fn () => $this->finishRegistration($response, $destination, $claims, $challengeId, $linkToken, $body));
            })
            ->orElseGet(fn () => $this->renderError($response, 'This link is invalid or expired. Return to ORK to get a fresh one.'));
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $body
     */
    private function finishRegistration(
        Response $response,
        string $destination,
        array $claims,
        string $challengeId,
        string $linkToken,
        array $body,
    ): Response {
        $password = (string) ($body['password'] ?? '');
        $confirm = (string) ($body['confirmPassword'] ?? '');
        if ($password === '') {
            return $this->renderForm($response, $linkToken, $challengeId, true, null);
        }
        if ($password !== $confirm) {
            return $this->renderForm($response, $linkToken, $challengeId, true, 'Passwords do not match.');
        }

        return Optional::ofNullable($this->orkProfileRepository->findByMundaneId($claims['mundane_id']))
            ->map(fn () => $this->renderError($response, 'That ORK profile is already linked to a different Amtgard account.'))
            ->orElseGet(function () use ($response, $destination, $claims, $challengeId, $linkToken, $body) {
                $result = $this->registrationService->register(
                    (string) ($body['firstName'] ?? ''),
                    (string) ($body['lastName'] ?? ''),
                    $destination,
                    (string) ($body['password'] ?? ''),
                );
                if (!$result['ok']) {
                    return $this->renderForm($response, $linkToken, $challengeId, true, $result['error']);
                }

                return $this->finishLink($response, $result['user'], $claims, $challengeId, true);
            });
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function finishLink(
        Response $response,
        UserEntity $user,
        array $claims,
        string $challengeId,
        bool $createdUser,
    ): Response {
        return Optional::ofNullable($this->orkProfileRepository->findByMundaneId($claims['mundane_id']))
            ->filter(fn ($existing) => $existing->getUserId() !== $user->getId())
            ->map(fn () => $this->renderError($response, 'That ORK profile is already linked to a different Amtgard account.'))
            ->orElseGet(function () use ($response, $user, $claims, $challengeId, $createdUser) {
                try {
                    $consumedJti = $this->tokenService->consumeJti($claims['jti']);
                } catch (\Throwable $e) {
                    $this->logger->error('ConnectController: consumeJti failed', [
                        'challenge_id' => $challengeId,
                        'mundane_id' => $claims['mundane_id'],
                        'msg' => $e->getMessage(),
                    ]);

                    return $this->renderError($response, 'Something went wrong on our end. Your link is still valid — please try again.');
                }
                if (!$consumedJti) {
                    return $this->renderError($response, 'This link has already been used. Return to ORK to get a fresh one.');
                }

                $this->challenges->consume($challengeId);

                try {
                    $this->orkProfileRepository->linkExistingUserToMundane($user->getId(), $claims['mundane_id'], 'ork_handoff');
                } catch (\RuntimeException $e) {
                    $this->logger->error('ConnectController: link write failed', [
                        'challenge_id' => $challengeId,
                        'idp_user_id' => $user->getUserId(),
                        'mundane_id' => $claims['mundane_id'],
                        'created_user' => $createdUser,
                        'msg' => $e->getMessage(),
                    ]);
                    if (str_contains($e->getMessage(), 'conflict')) {
                        return $this->renderError($response, 'That ORK profile is already linked to a different Amtgard account.');
                    }

                    return $this->renderError($response, 'Something went wrong on our end. Please try again or return to ORK to get a fresh link.');
                } catch (\Throwable $e) {
                    $this->logger->error('ConnectController: link write failed', [
                        'challenge_id' => $challengeId,
                        'idp_user_id' => $user->getUserId(),
                        'mundane_id' => $claims['mundane_id'],
                        'created_user' => $createdUser,
                        'msg' => $e->getMessage(),
                    ]);

                    return $this->renderError($response, 'Something went wrong on our end. Please try again or return to ORK to get a fresh link.');
                }

                if (session_status() !== PHP_SESSION_ACTIVE) {
                    session_start();
                }
                if (!session_regenerate_id(true)) {
                    $this->logger->warning('session_regenerate_id failed during connect handoff', [
                        'challenge_id' => $challengeId,
                    ]);
                }
                $_SESSION['user_id'] = $user->getUserId();

                return $this->redirectBackToOrk($response, $user->getUserId(), $claims['mundane_id'], $challengeId);
            });
    }

    private function redirectBackToOrk(Response $response, string $idpUserId, int $mundaneId, string $challengeId): Response
    {
        if (!$this->tokenService->hasSharedSecret()) {
            return $response->withHeader('Location', $this->tokenService->orkBaseUrl() . '/')->withStatus(302);
        }

        $jwt = $this->tokenService->mintFlowBCompletion($idpUserId, $mundaneId, $challengeId);

        return $response->withHeader('Location', $this->tokenService->flowBCompletionRedirectUrl($jwt))->withStatus(302);
    }

    private function destinationForHint(string $idpEmail): string
    {
        $hint = strtolower(trim($idpEmail));

        return Optional::ofNullable($this->users->getUserByEmail($hint))
            ->map(fn (UserEntity $user) => strtolower(trim((string) $user->getEmail())))
            ->orElse($hint);
    }

    private function renderError(Response $response, string $message): Response
    {
        $response->getBody()->write($this->twig->render('connect.twig', [
            'handoff' => 'legacy',
            'link_token' => '',
            'challenge_id' => '',
            'needs_password' => false,
            'email' => '',
            'defaultTab' => 'login',
            'error' => $message,
            'notice' => null,
        ]));

        return $response->withStatus(400);
    }

    private function renderForm(
        Response $response,
        string $linkToken,
        string $challengeId,
        bool $needsPassword,
        ?string $error,
    ): Response {
        $response->getBody()->write($this->twig->render('connect.twig', [
            'handoff' => 'code',
            'link_token' => $linkToken,
            'challenge_id' => $challengeId,
            'needs_password' => $needsPassword,
            'email' => '',
            'defaultTab' => 'login',
            'error' => $error,
            'notice' => 'If that mailbox can receive mail, a code was sent. The ORK and Amtgard addresses do not need to match.',
        ]));

        return $response;
    }

    private function renderLegacyForm(Response $response, string $linkToken, string $email, string $tab, string $message): Response
    {
        $response->getBody()->write($this->twig->render('connect.twig', [
            'handoff' => 'legacy',
            'link_token' => $linkToken,
            'email' => $email,
            'defaultTab' => $tab,
            'challenge_id' => '',
            'needs_password' => false,
            'error' => $message,
            'notice' => null,
        ]));

        return $response;
    }
}
