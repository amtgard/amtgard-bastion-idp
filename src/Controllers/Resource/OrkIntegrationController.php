<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Services\Ork\OrkClaimSendNonceService;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class OrkIntegrationController
{
    public function __construct(
        private OrkClaimSendNonceService $sendNonces,
        private LoggerInterface $logger,
    ) {}

    #[OA\Post(
        path: '/resources/ork/validate-send-nonce',
        operationId: 'validateOrkClaimSendNonce',
        summary: 'Validate a Flow A send nonce (ORK server-to-server)',
        description: 'ORK calls this synchronously from BeginClaimOrkMail before mailing a claim code. The nonce is single-use. Restricted to confidential clients in LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS.',
        tags: ['ORK Integration'],
        security: [['orkConfidentialClient' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['send_nonce'],
                    properties: [
                        new OA\Property(property: 'send_nonce', type: 'string', description: 'Opaque nonce minted by the IDP when the user requested Email Code.'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Nonce accepted and consumed.',
                content: new OA\JsonContent(
                    required: ['idp_user_id', 'mundane_id'],
                    properties: [
                        new OA\Property(property: 'idp_user_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'mundane_id', type: 'integer', minimum: 1),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Unknown, expired, or already-used nonce.',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
        ]
    )]
    public function validateSendNonce(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $sendNonce = trim((string) ($body['send_nonce'] ?? ''));

        $claims = $this->sendNonces->validateAndConsumeSendNonce($sendNonce);
        if ($claims === null) {
            $this->logger->info('ork.claim.send_nonce_rejected', [
                'reason' => 'invalid_or_expired',
            ]);

            $response->getBody()->write((string) json_encode(['error' => 'invalid_send_nonce']));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $this->logger->info('ork.claim.send_nonce_consumed', [
            'idp_user_id' => $claims['idp_user_id'],
            'mundane_id' => $claims['mundane_id'],
        ]);

        $response->getBody()->write((string) json_encode([
            'idp_user_id' => $claims['idp_user_id'],
            'mundane_id' => $claims['mundane_id'],
        ]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
}
