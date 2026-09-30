<?php

declare(strict_types=1);


namespace Amtgard\IdP\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Optional\Optional;
use Psr\Log\LoggerInterface;

final class OrkService
{
    private const BASE_URL = 'https://ork.amtgard.com/orkservice/Json/index.php';

    private ClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(ClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    public function authorize(string $username, string $password): ?array
    {
        try {
            $response = $this->httpClient->get(self::BASE_URL, [
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
            $response = $this->httpClient->get(self::BASE_URL, [
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
                $this->logger->info('ORK GetPlayer success', [
                    'mundaneId' => $mundaneId,
                    'parkIdKeyPresent' => array_key_exists('ParkId', $data['Player']),
                    'parkIdRaw' => $data['Player']['ParkId'] ?? null,
                    'parkRelatedFields' => $this->extractParkRelatedFields($data['Player']),
                ]);
                return $data['Player'];
            }

            $this->logger->warning('ORK GetPlayer failed', [
                'response' => $data,
                'mundaneId' => $mundaneId,
                'httpStatus' => $response->getStatusCode(),
            ]);
            return null;

        } catch (GuzzleException $e) {
            $this->logger->error('ORK GetPlayer exception', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Public username search. The result never includes the ORK email.
     *
     * @return list<array{mundaneId: int, username: string, persona: string, parkName: string, kingdomName: string}>
     */
    public function searchUsernames(string $term, int $limit = 8): array
    {
        $term = trim($term);
        if (strlen($term) < 2) {
            return [];
        }

        $limit = max(1, min(10, $limit));

        try {
            $response = $this->httpClient->get(self::BASE_URL, [
                'query' => [
                    'call' => 'SearchService/Player',
                    'type' => 'USER',
                    'search' => $term,
                    'limit' => (string) $limit,
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            $rows = is_array($data['Result'] ?? null) ? $data['Result'] : [];
            $matches = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $username = trim((string) ($row['UserName'] ?? ''));
                $mundaneId = (int) ($row['MundaneId'] ?? 0);
                if ($username === '' || $mundaneId <= 0) {
                    continue;
                }
                $matches[] = [
                    'mundaneId' => $mundaneId,
                    'username' => $username,
                    'persona' => trim((string) ($row['Persona'] ?? '')),
                    'parkName' => trim((string) ($row['ParkName'] ?? '')),
                    'kingdomName' => trim((string) ($row['KingdomName'] ?? '')),
                ];
            }

            return $matches;
        } catch (GuzzleException $e) {
            $this->logger->error('ORK username search exception', ['exception' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Player record used to mail the address stored on the ORK mundane.
     * Callers must not return Email to the browser.
     *
     * @return array<string, mixed>|null
     */
    public function getPlayerByMundaneId(int $mundaneId): ?array
    {
        if ($mundaneId <= 0) {
            return null;
        }

        try {
            $response = $this->httpClient->get(self::BASE_URL, [
                'query' => [
                    'call' => 'Player/GetPlayer',
                    'request' => [
                        'MundaneId' => $mundaneId,
                    ],
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['Status']['Status']) && $data['Status']['Status'] === 0 && isset($data['Player']) && is_array($data['Player'])) {
                return $data['Player'];
            }

            $this->logger->warning('ORK GetPlayer by mundane id failed', [
                'mundaneId' => $mundaneId,
                'status' => $data['Status'] ?? null,
            ]);

            return null;
        } catch (GuzzleException $e) {
            $this->logger->error('ORK GetPlayer by mundane id exception', [
                'mundaneId' => $mundaneId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
    public function getParkShortInfo(int $parkId): ?array
    {
        $request = [
            'call' => 'Park/GetParkShortInfo',
            'ParkId' => $parkId,
        ];

        if ($parkId <= 0) {
            return null;
        }

        try {
            $response = $this->httpClient->get(self::BASE_URL, [
                'query' => [
                    'call' => $request['call'],
                    'request' => [
                        'ParkId' => $parkId,
                    ],
                ],
                'on_stats' => function (\GuzzleHttp\TransferStats $stats) use ($parkId, $request) {
                    $this->logger->info('ORK GetParkShortInfo Request', [
                        'url' => (string) $stats->getEffectiveUri(),
                        'parkId' => $parkId,
                        'request' => $request,
                    ]);
                },
            ]);

            $httpStatus = $response->getStatusCode();
            $rawBody = $response->getBody()->getContents();
            $data = json_decode($rawBody, true);

            // Based on user sample: Status->Status === 0 means success
            if (isset($data['Status']['Status']) && $data['Status']['Status'] === 0) {
                $this->logger->info('ORK GetParkShortInfo success', [
                    'parkId' => $parkId,
                    'httpStatus' => $httpStatus,
                    'parkName' => $data['ParkInfo']['ParkName'] ?? null,
                    'kingdomName' => $data['KingdomInfo']['KingdomName'] ?? null,
                ]);
                return $data;
            }

            $this->logger->warning('ORK GetParkShortInfo failed', [
                'parkId' => $parkId,
                'httpStatus' => $httpStatus,
                'request' => $request,
                'response' => $data,
                'rawBody' => $rawBody,
            ]);
            return null;

        } catch (GuzzleException $e) {
            $this->logger->error('ORK GetParkShortInfo exception', [
                'parkId' => $parkId,
                'request' => $request,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $playerData
     * @return array<string, mixed>|null
     */
    public function resolveParkDataFromPlayer(array $playerData, int $userId, string $flow): ?array
    {
        $parkIdRaw = $playerData['ParkId'] ?? null;
        $parkIdOpt = Optional::ofNullable($parkIdRaw)
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $id) => $id > 0);

        $this->logger->info("{$flow}: resolving park data from player", [
            'userId' => $userId,
            'mundaneId' => $playerData['MundaneId'] ?? null,
            'parkIdKeyPresent' => array_key_exists('ParkId', $playerData),
            'parkIdRaw' => $parkIdRaw,
            'parkIdResolved' => $parkIdOpt->orElse(null),
            'parkRelatedFields' => $this->extractParkRelatedFields($playerData),
        ]);

        if (!$parkIdOpt->isPresent()) {
            return null;
        }

        $parkId = $parkIdOpt->get();
        $parkData = $this->getParkShortInfo($parkId);

        if ($parkData === null) {
            $this->logger->warning("{$flow}: park lookup returned no data", [
                'userId' => $userId,
                'mundaneId' => $playerData['MundaneId'] ?? null,
                'parkIdResolved' => $parkId,
            ]);
        }

        return $parkData;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractParkRelatedFields(array $data): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (stripos($key, 'park') !== false || stripos($key, 'kingdom') !== false) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }
}
