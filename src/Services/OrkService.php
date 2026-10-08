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
            $response = $this->httpClient->get($this->jsonApiBaseUrl(), [
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
            $response = $this->httpClient->get($this->jsonApiBaseUrl(), [
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
            $response = $this->httpClient->get($this->jsonApiBaseUrl(), [
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
            $response = $this->httpClient->get($this->jsonApiBaseUrl(), [
                'query' => [
                    'call' => 'Player/GetPlayer',
                    'request' => [
                        'MundaneId' => $mundaneId,
                    ],
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['Status']['Status']) && $data['Status']['Status'] === 0 && isset($data['Player']) && is_array($data['Player'])) {
                $player = $data['Player'];
                $emailRaw = trim((string) ($player['Email'] ?? ''));
                $this->logger->info('ORK GetPlayer by mundane id success', [
                    'mundaneId' => $mundaneId,
                    'emailKeyPresent' => array_key_exists('Email', $player),
                    'emailRawLength' => strlen($emailRaw),
                    'emailRawHasAt' => str_contains($emailRaw, '@'),
                    'suspended' => $player['Suspended'] ?? null,
                    'penaltyBox' => $player['PenaltyBox'] ?? null,
                    'username' => $player['UserName'] ?? $player['username'] ?? null,
                    'persona' => $player['Persona'] ?? null,
                ]);

                return $player;
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
            $response = $this->httpClient->get($this->jsonApiBaseUrl(), [
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

    private function jsonApiBaseUrl(): string
    {
        $override = trim((string) ($_ENV['ORK_JSON_API_BASE_URL'] ?? ''));

        return $override !== '' ? rtrim($override, '/') : self::BASE_URL;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function isOrkJsonSuccess(?array $data): bool
    {
        if ($data === null) {
            return false;
        }

        if (isset($data['Status']) && is_array($data['Status'])) {
            return (int) ($data['Status']['Status'] ?? -1) === 0;
        }

        if ($data['Status'] === true || (int) ($data['Status'] ?? -1) === 0) {
            return true;
        }

        return (int) ($data['Code'] ?? -1) === 0 && ($data['Status'] ?? false) !== false;
    }

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

    /**
     * Flow A v2: ORK mails the claim code after validating send_nonce with the IDP.
     */
    public function beginClaimOrkMail(string $sendNonce): bool
    {
        try {
            $response = $this->httpClient->get($this->jsonApiBaseUrl(), [
                'query' => [
                    'call' => 'IdpIntegration/BeginClaimOrkMail',
                    'request' => [
                        'SendNonce' => $sendNonce,
                    ],
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            $ok = $this->isOrkJsonSuccess(is_array($data) ? $data : null);
            $this->logger->info('ORK BeginClaimOrkMail', [
                'ok' => $ok,
                'status' => is_array($data) ? ($data['Status'] ?? null) : null,
                'code' => is_array($data) ? ($data['Code'] ?? null) : null,
            ]);

            return $ok;
        } catch (GuzzleException $e) {
            $this->logger->error('ORK BeginClaimOrkMail exception', ['exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array{email: string, mundaneId: int}|null
     */
    public function validateClaimOrkCode(string $idpUserId, int $mundaneId, string $code): ?array
    {
        try {
            $response = $this->httpClient->get($this->jsonApiBaseUrl(), [
                'query' => [
                    'call' => 'IdpIntegration/ValidateClaimOrkCode',
                    'request' => [
                        'IdpUserId' => $idpUserId,
                        'MundaneId' => $mundaneId,
                        'Code' => $code,
                    ],
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data) || !$this->isOrkJsonSuccess($data)) {
                $this->logger->info('ORK ValidateClaimOrkCode rejected', [
                    'mundane_id' => $mundaneId,
                    'status' => is_array($data) ? ($data['Status'] ?? null) : null,
                ]);

                return null;
            }

            $returnedMundane = (int) ($data['MundaneId'] ?? 0);
            $email = trim((string) ($data['Email'] ?? ''));
            if ($returnedMundane <= 0 || $email === '') {
                return null;
            }

            return [
                'email' => $email,
                'mundaneId' => $returnedMundane,
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('ORK ValidateClaimOrkCode exception', [
                'mundane_id' => $mundaneId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
