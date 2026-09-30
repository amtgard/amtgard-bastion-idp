<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Guzzle client whose transport returns canned OAuth/ORK JSON (Fake Object + Strategy dispatch).
 */
final class DevIntegHttpClient extends Client
{
    public const INTEG_MUNDANE_ID = 90042;

    public const INTEG_ORK_USERNAME = 'integ-ork';

    private const INTEG_DENY_CODE = 'integ-deny';

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct([
            'handler' => HandlerStack::create(
                static function (RequestInterface $request, array $options) use ($logger): \GuzzleHttp\Promise\PromiseInterface {
                    $response = self::dispatch($request, $options, $logger);

                    return Create::promiseFor($response);
                }
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function dispatch(RequestInterface $request, array $options, LoggerInterface $logger): ResponseInterface
    {
        $uri = $request->getUri();
        $host = strtolower($uri->getHost());
        $path = $uri->getPath();

        $logger->info('DevIntegHttpClient answered request', ['host' => $host]);

        if ($host === 'ork.amtgard.com') {
            return self::orkResponse($request, $options);
        }

        if (self::isOAuthTokenPath($path) && self::requestUsesDenyCode($request)) {
            return new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}');
        }

        return match ($host) {
            'oauth2.googleapis.com' => self::jsonResponse([
                'access_token' => 'integ-google-access',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            'openidconnect.googleapis.com' => self::jsonResponse([
                'sub' => 'integ-google-sub',
                'email' => 'integ-google@example.com',
                'name' => 'Integ Google',
                'given_name' => 'Integ',
                'family_name' => 'Google',
            ]),
            'graph.facebook.com' => self::facebookGraphResponse($path),
            'discord.com' => self::discordResponse($path),
            'appleid.apple.com' => self::appleResponse($path),
            default => throw new RequestException(
                sprintf('DevIntegHttpClient has no canned response for host %s', $host),
                $request
            ),
        };
    }

    private static function isOAuthTokenPath(string $path): bool
    {
        return str_contains($path, '/token') || str_contains($path, '/oauth/access_token');
    }

    private static function requestUsesDenyCode(RequestInterface $request): bool
    {
        $body = (string) $request->getBody();
        if ($body !== '') {
            parse_str($body, $parsed);
            if (($parsed['code'] ?? '') === self::INTEG_DENY_CODE) {
                return true;
            }
        }

        parse_str($request->getUri()->getQuery(), $query);

        return ($query['code'] ?? '') === self::INTEG_DENY_CODE;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function orkResponse(RequestInterface $request, array $options): ResponseInterface
    {
        $query = $options['query'] ?? [];
        if ($query === []) {
            parse_str($request->getUri()->getQuery(), $parsed);
            $query = $parsed;
        }

        $call = $query['call'] ?? '';
        $requestPayload = $query['request'] ?? [];
        if (!is_array($requestPayload)) {
            $requestPayload = [];
        }

        if ($call === 'Authorization/Authorize') {
            $username = $requestPayload['UserName'] ?? '';
            if ($username === self::INTEG_ORK_USERNAME) {
                return self::jsonResponse([
                    'Status' => ['Status' => 0],
                    'Token' => 'integ-ork-token',
                ]);
            }

            return self::jsonResponse(['Status' => ['Status' => 1]]);
        }

        if ($call === 'Player/GetPlayer') {
            $mundaneId = (int) ($requestPayload['MundaneId'] ?? 0);
            if ($mundaneId === self::INTEG_MUNDANE_ID) {
                return self::jsonResponse([
                    'Status' => ['Status' => 0],
                    'Player' => [
                        'name' => 'IntegOrk',
                        'MundaneId' => self::INTEG_MUNDANE_ID,
                        'ParkId' => 42,
                        'KingdomName' => 'Dragonspine',
                    ],
                ]);
            }

            return self::jsonResponse(['Status' => ['Status' => 1]]);
        }

        if ($call === 'Park/GetParkShortInfo') {
            return self::jsonResponse([
                'Status' => ['Status' => 0],
                'ParkInfo' => ['ParkName' => 'Integ Park'],
                'KingdomInfo' => ['KingdomName' => 'Dragonspine'],
            ]);
        }

        return self::jsonResponse(['Status' => ['Status' => 1]]);
    }

    private static function facebookGraphResponse(string $path): ResponseInterface
    {
        if (str_contains($path, '/oauth/access_token')) {
            return self::jsonResponse([
                'access_token' => 'integ-facebook-access',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]);
        }

        if (str_contains($path, '/me')) {
            return self::jsonResponse([
                'id' => 'integ-facebook-id',
                'name' => 'Integ Facebook',
                'email' => 'integ-facebook@example.com',
            ]);
        }

        return self::jsonResponse(['error' => ['message' => 'unsupported facebook path']], 404);
    }

    private static function discordResponse(string $path): ResponseInterface
    {
        if (str_contains($path, '/oauth2/token')) {
            return self::jsonResponse([
                'access_token' => 'integ-discord-access',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]);
        }

        if (str_contains($path, '/users/@me')) {
            return self::jsonResponse([
                'id' => 'integ-discord-id',
                'username' => 'integdiscord',
                'email' => 'integ-discord@example.com',
            ]);
        }

        return self::jsonResponse(['message' => 'unsupported discord path'], 404);
    }

    private static function appleResponse(string $path): ResponseInterface
    {
        if (str_ends_with($path, '/auth/keys')) {
            return self::jsonResponse(self::appleJwks());
        }

        if (str_ends_with($path, '/auth/token')) {
            return self::jsonResponse([
                'access_token' => 'integ-apple-access',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'id_token' => self::appleIdToken(),
            ]);
        }

        return self::jsonResponse(['error' => 'unsupported_apple_path'], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private static function appleJwks(): array
    {
        $pemPath = dirname(__DIR__, 3) . '/vendor/code-rhapsodie/oauth2-apple/test/src/private_key.pem';
        $details = openssl_pkey_get_details(openssl_pkey_get_private('file://' . $pemPath));
        $n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

        return [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'integ-apple-test',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $n,
                'e' => $e,
            ]],
        ];
    }

    private static function appleIdToken(): string
    {
        $pemPath = dirname(__DIR__, 3) . '/vendor/code-rhapsodie/oauth2-apple/test/src/private_key.pem';
        $privateKey = openssl_pkey_get_private('file://' . $pemPath);

        return JWT::encode([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'integ-apple-client',
            'sub' => 'integ-apple-sub',
            'email' => 'integ-apple@example.com',
            'email_verified' => true,
        ], $privateKey, 'RS256', 'integ-apple-test');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function jsonResponse(array $payload, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }
}
