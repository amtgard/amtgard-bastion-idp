<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Middleware\ConfidentialClientAuthMiddleware;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Utility\Client\ClientResourcesRequestResolver;
use Amtgard\IdP\Utility\JsonResponseBody;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Interpreter: maps a confidential-client request onto the caller and the target user.
 */
final class ClientIamRequestInterpreter
{
    private const ERROR_IDP_USER_ID_REQUIRED = 'idp_user_id is required';
    private const ERROR_UNKNOWN_IDP_USER_ID = 'unknown idp_user_id';
    private const ERROR_LOGIN_ID_REQUIRED = 'login_id is required';
    private const ERROR_UNKNOWN_LOGIN_ID = 'unknown login_id for user';

    public function __construct(
        private ClientResourcesRequestResolver $users,
    ) {}

    public function client(Request $request): Client
    {
        /** @var Client $client */
        $client = $request->getAttribute(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE);

        return $client;
    }

    public function findUserByEmail(string $email): Optional
    {
        return $this->users->findUserByEmail($email);
    }

    public function requireUser(mixed $idpUserId, Response $response): UserEntity|Response
    {
        // JSON/query params arrive untyped. Blank or non-string is a client error;
        // lookup is reserved for a well-formed public id so a miss stays 404.
        $publicId = is_string($idpUserId) ? trim($idpUserId) : '';
        if ($publicId === '') {
            return JsonResponseBody::writeError($response, self::ERROR_IDP_USER_ID_REQUIRED, 400);
        }

        return $this->users->findUserByPublicId($publicId)
            ->orElseGet(fn () => JsonResponseBody::writeError($response, self::ERROR_UNKNOWN_IDP_USER_ID, 404));
    }

    /**
     * @param array<string, mixed> $body
     * @return array{user: UserEntity, loginId: int}|Response
     */
    public function requireUserAndLogin(array $body, Response $response): array|Response
    {
        $user = $this->requireUser($body['idp_user_id'] ?? null, $response);
        if ($user instanceof Response) {
            return $user;
        }

        return $this->requireLoginForUser($body['login_id'] ?? null, $user, $response);
    }

    /**
     * @return array{user: UserEntity, loginId: int}|Response
     */
    public function requireUserAndLoginFromQuery(
        string $idpUserId,
        Request $request,
        Response $response
    ): array|Response {
        $user = $this->requireUser($idpUserId, $response);
        if ($user instanceof Response) {
            return $user;
        }

        return $this->requireLoginForUser($request->getQueryParams()['login_id'] ?? null, $user, $response);
    }

    /**
     * @return array{user: UserEntity, loginId: int}|Response
     */
    private function requireLoginForUser(mixed $loginId, UserEntity $user, Response $response): array|Response
    {
        if (!is_numeric($loginId) || (int) $loginId <= 0) {
            return JsonResponseBody::writeError($response, self::ERROR_LOGIN_ID_REQUIRED, 400);
        }

        return $this->users->findLoginIdForUser((int) $loginId, $user->getId())
            ->map(fn (int $resolvedLoginId) => ['user' => $user, 'loginId' => $resolvedLoginId])
            ->orElseGet(fn () => JsonResponseBody::writeError($response, self::ERROR_UNKNOWN_LOGIN_ID, 404));
    }
}
