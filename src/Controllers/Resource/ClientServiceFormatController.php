<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Services\ClientIamServiceFormatService;
use Amtgard\IdP\Utility\JsonResponseBody;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * HTTP edge for a confidential client's IAM proviso layout.
 */
final class ClientServiceFormatController
{
    public function __construct(
        private ClientIamRequestInterpreter $requests,
        private ClientIamServiceFormatService $formats,
    ) {}

    #[OA\Get(
        path: '/resources/client/service-format',
        operationId: 'clientGetServiceFormat',
        summary: 'Get IAM service format (proviso slot layout) for this client',
        tags: ['Client'],
        security: [['clientBasicAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Service format'),
        ]
    )]
    public function getServiceFormat(Request $request, Response $response): Response
    {
        $client = $this->requests->client($request);

        return JsonResponseBody::write($response, $this->formats->payload($client), 200);
    }

    #[OA\Post(
        path: '/resources/client/service-format',
        operationId: 'clientCreateServiceFormat',
        summary: 'Set IAM service format when none is configured yet',
        tags: ['Client'],
        security: [['clientBasicAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['service_format'],
                properties: [
                    new OA\Property(
                        property: 'service_format',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        example: ['Configuration', 'Game', 'Kingdom', 'Park']
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Service format created'),
            new OA\Response(response: 400, description: 'Invalid service_format'),
            new OA\Response(response: 409, description: 'Service format already configured'),
        ]
    )]
    public function createServiceFormat(Request $request, Response $response): Response
    {
        $client = $this->requests->client($request);

        if ($this->formats->hasConfiguredFormat($client)) {
            return JsonResponseBody::writeError($response, 'service format already configured; use PUT to replace', 409);
        }

        return $this->saveServiceFormat($request, $response, $client);
    }

    #[OA\Put(
        path: '/resources/client/service-format',
        operationId: 'clientReplaceServiceFormat',
        summary: 'Replace IAM service format (proviso slot layout)',
        tags: ['Client'],
        security: [['clientBasicAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['service_format'],
                properties: [
                    new OA\Property(
                        property: 'service_format',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        example: ['Configuration', 'Kingdom', 'EventInstance']
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Service format updated'),
            new OA\Response(response: 400, description: 'Invalid service_format'),
        ]
    )]
    public function replaceServiceFormat(Request $request, Response $response): Response
    {
        return $this->saveServiceFormat($request, $response, $this->requests->client($request));
    }

    private function saveServiceFormat(Request $request, Response $response, Client $client): Response
    {
        try {
            $this->formats->save($client, (array) $request->getParsedBody());
        } catch (\InvalidArgumentException|\JsonException $e) {
            return JsonResponseBody::writeError($response, $e->getMessage(), 400);
        }

        return $response->withStatus(204);
    }
}
