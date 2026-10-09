<?php

declare(strict_types=1);


namespace Amtgard\IdP\Services;

use Amtgard\IdP\Utility\Log\IdpLogging;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Optional\Optional;

final class OrkService
{
    private const BASE_URL = 'https://ork.amtgard.com/orkservice/Json/index.php';

    private ClientInterface $httpClient;
    private IdpLogging $idpLogging;

    public function __construct(ClientInterface $httpClient, IdpLogging $idpLogging)
    {
        $this->httpClient = $httpClient;
        $this->idpLogging = $idpLogging;
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
                'on_stats' => function (\GuzzleHttp\TransferStats $stats): void {
                    $this->idpLogging->app()->debug('ork authorization request', [
                        'call' => 'Authorization/Authorize',
                    ]);
                }
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['Status']['Status']) && $data['Status']['Status'] === 0) {
                return $data;
            }

            $context = ['reason' => 'ork_authorization_failed'];
            if (str_contains($username, '@')) {
                $context['email_sha256'] = hash('sha256', strtolower($username));
            }
            $this->idpLogging->app()->error('ork authorization failed', $context);
            return null;

        } catch (GuzzleException $e) {
            $this->idpLogging->app()->error('ork authorization exception', [
                'exception_class' => $e::class,
            ]);
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
                return $data['Player'];
            }

            $this->idpLogging->app()->error('ork get player failed', [
                'mundane_id' => $mundaneId,
                'http_status' => $response->getStatusCode(),
            ]);
            return null;

        } catch (GuzzleException $e) {
            $this->idpLogging->app()->error('ork get player exception', [
                'mundane_id' => $mundaneId,
                'exception_class' => $e::class,
            ]);
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
            $this->idpLogging->app()->error('ork username search exception', [
                'exception_class' => $e::class,
            ]);

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
                return $data['Player'];
            }

            $this->idpLogging->app()->error('ork get player by mundane id failed', [
                'mundane_id' => $mundaneId,
            ]);

            return null;
        } catch (GuzzleException $e) {
            $this->idpLogging->app()->error('ork get player by mundane id exception', [
                'mundane_id' => $mundaneId,
                'exception_class' => $e::class,
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
                'on_stats' => function (\GuzzleHttp\TransferStats $stats) use ($parkId): void {
                    $this->idpLogging->app()->debug('ork get park request', [
                        'park_id' => $parkId,
                    ]);
                },
            ]);

            $httpStatus = $response->getStatusCode();
            $rawBody = $response->getBody()->getContents();
            $data = json_decode($rawBody, true);

            // Based on user sample: Status->Status === 0 means success
            if (isset($data['Status']['Status']) && $data['Status']['Status'] === 0) {
                return $data;
            }

            $this->idpLogging->app()->error('ork get park failed', [
                'park_id' => $parkId,
                'http_status' => $httpStatus,
            ]);
            return null;

        } catch (GuzzleException $e) {
            $this->idpLogging->app()->error('ork get park exception', [
                'park_id' => $parkId,
                'exception_class' => $e::class,
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

        $this->idpLogging->app()->debug('ork resolve park from player', [
            'flow' => $flow,
            'user_id' => $userId,
            'mundane_id' => $playerData['MundaneId'] ?? null,
            'park_id_resolved' => $parkIdOpt->orElse(null),
        ]);

        if (!$parkIdOpt->isPresent()) {
            return null;
        }

        $parkId = $parkIdOpt->get();
        $parkData = $this->getParkShortInfo($parkId);

        if ($parkData === null) {
            $this->idpLogging->app()->warning('ork park lookup empty', [
                'flow' => $flow,
                'user_id' => $userId,
                'mundane_id' => $playerData['MundaneId'] ?? null,
                'park_id' => $parkId,
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
            $this->idpLogging->app()->info('ork begin claim ork mail', [
                'ok' => $ok,
            ]);

            return $ok;
        } catch (GuzzleException $e) {
            $this->idpLogging->app()->error('ork begin claim ork mail exception', [
                'exception_class' => $e::class,
            ]);

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
                $this->idpLogging->app()->info('ork validate claim ork code rejected', [
                    'mundane_id' => $mundaneId,
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
            $this->idpLogging->app()->error('ork validate claim ork code exception', [
                'mundane_id' => $mundaneId,
                'exception_class' => $e::class,
            ]);

            return null;
        }
    }
}
