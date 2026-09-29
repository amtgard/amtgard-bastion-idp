<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\JsonResponseBody;
use Amtgard\IdP\Utility\Security\CurrentUserResolverInterface;
use OpenApi\Attributes as OA;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Adapter: maps browser and server-to-server unlink calls onto the ORK profile repository.
 */
final class OrkAccountUnlinkController
{
    public function __construct(
        private LoggerInterface $logger,
        private CurrentUserResolverInterface $currentUserResolver,
        private UserRepository $userRepository,
        private UserOrkProfileRepository $orkProfileRepository,
    ) {}

    public function unlinkFromProfile(Request $request, Response $response): Response
    {
        $user = $this->currentUserResolver->resolve();
        if ($user === null) {
            return $response->withHeader('Location', '/auth/login')->withStatus(302);
        }

        $this->unlinkStoredProfile($user->getId());

        return $response
            ->withHeader('Location', '/resources/profile?success=unlinked')
            ->withStatus(302);
    }

    #[OA\Post(
        path: '/resources/unlink-ork-profile',
        operationId: 'unlinkOrkProfile',
        summary: 'Remove an ORK account link from the IDP (ORK server-to-server)',
        description: 'Deletes the `user_ork_profiles` row for an IDP user. Restricted to confidential clients listed in `LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS`. Idempotent when the user exists but has no ORK profile.',
        tags: ['ORK Integration'],
        security: [['orkConfidentialClient' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['idp_user_id'],
                    properties: [
                        new OA\Property(property: 'idp_user_id', type: 'string', format: 'uuid', description: 'IDP user UUID'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Profile removed, or the user had no ORK profile'),
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
        ]
    )]
    public function unlinkOrkProfile(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $idpUserId = Optional::ofNullable($body['idp_user_id'] ?? null)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->orElse(null);

        if ($idpUserId === null) {
            return JsonResponseBody::writeError($response, 'idp_user_id (string) is required', 400);
        }

        $user = $this->userRepository->findUserByUserId($idpUserId);
        if ($user === null) {
            $this->logger->info('unlinkOrkProfile unknown idp_user_id', ['idp_user_id' => $idpUserId]);

            return JsonResponseBody::writeError($response, 'unknown idp_user_id', 404);
        }

        $this->unlinkStoredProfile($user->getId());

        return $response->withStatus(204);
    }

    private function unlinkStoredProfile(int $userId): void
    {
        $profile = $this->orkProfileRepository->findByUserId($userId);
        if ($profile === null) {
            $this->logger->info('ork profile unlink skipped', [
                'user_id' => $userId,
                'reason' => 'not_linked',
            ]);

            return;
        }

        $mundaneId = $profile->getMundaneId();
        $removed = $this->orkProfileRepository->unlinkByUserId($userId);
        if (!$removed) {
            $this->logger->info('ork profile unlink skipped', [
                'user_id' => $userId,
                'reason' => 'not_linked',
            ]);

            return;
        }

        $this->logger->info('ork profile unlinked', [
            'user_id' => $userId,
            'mundane_id' => $mundaneId,
        ]);
    }
}
