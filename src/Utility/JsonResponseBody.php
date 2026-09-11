<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Psr\Http\Message\ResponseInterface;

final class JsonResponseBody
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function write(ResponseInterface $response, array $payload, ?int $status = null): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload));
        $response = $response->withHeader('Content-Type', 'application/json');

        if ($status !== null) {
            $response = $response->withStatus($status);
        }

        return $response;
    }

    public static function writeError(ResponseInterface $response, string $error, int $status): ResponseInterface
    {
        return self::write($response, ['error' => $error], $status);
    }
}
