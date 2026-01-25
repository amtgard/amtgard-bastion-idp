<?php

namespace Amtgard\IdP\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class OrkService
{
    private const BASE_URL = 'https://ork.amtgard.com/orkservice/Json/index.php';

    private Client $tempClient; // Using a temp client for now, or could inject if configured
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        $userAgent = $_ENV['ORK_API_USER_AGENT'] ?? null;
        $referer = $_ENV['ORK_API_REFERER'] ?? null;

        if (empty($userAgent) || empty($referer)) {
            throw new \RuntimeException('Missing required ORK API configuration: ORK_API_USER_AGENT and ORK_API_REFERER must be set.');
        }

        $this->tempClient = new Client([
            'verify' => false, // Disable SSL verification for legacy server
            'headers' => [
                'User-Agent' => $userAgent,
                'Referer' => $referer,
            ]
        ]);
    }

    public function authorize(string $username, string $password): ?array
    {
        try {
            $response = $this->tempClient->get(self::BASE_URL, [
                'query' => [
                    'call' => 'Authorization/Authorize',
                    'request' => [
                        'UserName' => $username,
                        'Password' => $password
                    ]
                ],
                'on_stats' => function (\GuzzleHttp\TransferStats $stats) use ($username) {
                    // Log the actual URL being called (redacting password manually if we were logging full query, but Guzzle query array handles encoding)
                    // Since we can't easily redact the query params from the stats URL if it's already built, we will just log that we made the call.
                    // Actually, let's log the URL path and the call param.
                    $this->logger->info('ORK Authorization Request', ['url' => (string) $stats->getEffectiveUri()]);
                }
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['Status']['Status']) && $data['Status']['Status'] === 0) {
                return $data;
            }

            $this->logger->warning('ORK Authorization failed', ['response' => $data, 'username' => $username]);
            return null;

        } catch (GuzzleException $e) {
            $this->logger->error('ORK Authorization exception', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    public function getPlayer(string $token, int $mundaneId): ?array
    {
        try {
            $response = $this->tempClient->get(self::BASE_URL, [
                'query' => [
                    'call' => 'Player/GetPlayer',
                    'request' => [
                        'Token' => $token,
                        'MundaneId' => $mundaneId
                    ]
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['Status']['Status']) && $data['Status']['Status'] === 0 && isset($data['Player'])) {
                return $data['Player'];
            }

            $this->logger->warning('ORK GetPlayer failed', ['response' => $data, 'mundaneId' => $mundaneId]);
            return null;

        } catch (GuzzleException $e) {
            $this->logger->error('ORK GetPlayer exception', ['exception' => $e->getMessage()]);
            return null;
        }
    }
    public function getParkShortInfo(int $parkId): ?array
    {
        try {
            $response = $this->tempClient->get(self::BASE_URL, [
                'query' => [
                    'call' => 'Park/GetParkShortInfo',
                    'request' => [
                        'ParkId' => $parkId
                    ]
                ],
                'on_stats' => function (\GuzzleHttp\TransferStats $stats) use ($parkId) {
                    $this->logger->info('ORK GetParkShortInfo Request', ['url' => (string) $stats->getEffectiveUri()]);
                }
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // Based on user sample: Status->Status === 0 means success
            if (isset($data['Status']['Status']) && $data['Status']['Status'] === 0) {
                return $data;
            }

            $this->logger->warning('ORK GetParkShortInfo failed', ['response' => $data, 'parkId' => $parkId]);
            return null;

        } catch (GuzzleException $e) {
            $this->logger->error('ORK GetParkShortInfo exception', ['exception' => $e->getMessage()]);
            return null;
        }
    }
}
