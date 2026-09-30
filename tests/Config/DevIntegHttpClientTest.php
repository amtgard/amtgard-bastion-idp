<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use DevIntegHttpClient;
use GuzzleHttp\Exception\RequestException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/config/container/integ/DevIntegHttpClient.php';

final class DevIntegHttpClientTest extends TestCase
{
    private DevIntegHttpClient $client;

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $this->logHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($this->logHandler);
        $this->client = new DevIntegHttpClient($logger);
    }

    public function testGoogleUserinfoReturnsCannedUser(): void
    {
        $response = $this->client->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo');
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('integ-google@example.com', $payload['email']);
        $this->assertTrue($this->logHandler->hasInfo('DevIntegHttpClient answered request'));
    }

    public function testIntegDenyCodeReturns400OnTokenRequest(): void
    {
        $response = $this->client->request('POST', 'https://oauth2.googleapis.com/token', [
            'body' => http_build_query(['code' => 'integ-deny', 'grant_type' => 'authorization_code']),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'http_errors' => false,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testOrkAuthorizeReturnsTokenAndUserId(): void
    {
        $response = $this->client->get('https://ork.amtgard.com/orkservice/Json/index.php', [
            'query' => [
                'call' => 'Authorization/Authorize',
                'request' => [
                    'UserName' => DevIntegHttpClient::INTEG_ORK_USERNAME,
                    'Password' => 'any',
                ],
            ],
        ]);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $payload['Status']['Status']);
        $this->assertSame('integ-ork-token', $payload['Token']);
        $this->assertSame(DevIntegHttpClient::INTEG_MUNDANE_ID, $payload['UserId']);
    }

    public function testOrkHostReturnsCannedPlayer(): void
    {
        $response = $this->client->get('https://ork.amtgard.com/orkservice/Json/index.php', [
            'query' => [
                'call' => 'Player/GetPlayer',
                'request' => [
                    'Token' => 'integ-ork-token',
                    'MundaneId' => DevIntegHttpClient::INTEG_MUNDANE_ID,
                ],
            ],
        ]);
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $payload['Status']['Status']);
        $this->assertSame('IntegOrk', $payload['Player']['Persona']);
    }

    public function testUnknownHostThrows(): void
    {
        $this->expectException(RequestException::class);
        $this->client->request('GET', 'https://unknown.integ.example/nope');
    }

    public function testAppleTokenResponseIncludesCannedIdTokenClaims(): void
    {
        $_ENV['APPLE_CLIENT_ID'] = 'apple_service_id';

        $keysResponse = $this->client->request('GET', 'https://appleid.apple.com/auth/keys');
        /** @var array<string, mixed> $jwks */
        $jwks = json_decode((string) $keysResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('EC', $jwks['keys'][0]['kty'] ?? null);

        $tokenResponse = $this->client->request('POST', 'https://appleid.apple.com/auth/token', [
            'body' => http_build_query(['code' => 'integ-ok', 'grant_type' => 'authorization_code']),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);
        /** @var array<string, mixed> $tokenPayload */
        $tokenPayload = json_decode((string) $tokenResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('integ-apple-access', $tokenPayload['access_token'] ?? null);
        $this->assertIsString($tokenPayload['id_token'] ?? null);
    }
}
