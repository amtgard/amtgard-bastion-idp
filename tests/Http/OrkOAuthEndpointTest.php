<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Http;

use DI\Bridge\Slim\Bridge;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Drives the ORK authorization-code path through the real container and Slim.
 * Social providers are not called. A logged-in session is planted directly.
 */
final class OrkOAuthEndpointTest extends TestCase
{
    private const CLIENT_ID = 'ork3-endpoint-test';

    private const REDIRECT_URI = 'https://ork.example.test/callback';

    private const EMAIL = 'ork-endpoint@example.test';

    private App $app;

    private string $userUuid = '';

    private string $codeVerifier;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $container = require $root . '/config/bootstrap.php';

        $_ENV['SESSION_REDIS_HOST'] = '';
        putenv('SESSION_REDIS_HOST');

        if (!$this->databaseIsReachable()) {
            $this->markTestSkipped('MySQL is not reachable; ORK endpoint test runs where the app database is.');
        }

        $this->app = Bridge::create($container);
        (require $root . '/config/middleware.php')($this->app);
        (require $root . '/config/routes.php')($this->app);

        $this->userUuid = Uuid::uuid4()->toString();
        $this->codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->seedFixtures();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = ['user_id' => $this->userUuid];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->deleteFixtures();
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAuthorizationCodeFlowReturnsUserinfo(): void
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $this->codeVerifier, true)), '+/', '-_'), '=');
        $authorize = '/oauth/authorize?' . http_build_query([
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'profile email',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $started = $this->request('GET', $authorize);
        $this->assertContains($started['status'], [301, 302], $started['body']);
        $this->assertStringContainsString('/oauth/approve', $started['location']);

        $approval = $this->request('GET', $started['location']);
        $this->assertSame(200, $approval['status'], $approval['body']);

        $allowed = $this->request('POST', '/oauth/approve', [
            'action' => 'allow',
            'callback' => '/oauth/authorize',
            '_csrf_token' => $_SESSION['csrf_token'] ?? '',
        ]);
        $this->assertContains($allowed['status'], [301, 302], $allowed['body']);

        $completed = $this->request('GET', $allowed['location']);
        $this->assertContains($completed['status'], [301, 302], $completed['body']);
        $code = $this->queryValue($completed['location'], 'code');
        $this->assertNotSame('', $code);

        unset($_SESSION['user_id'], $_SESSION['client_id']);

        $token = $this->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->codeVerifier,
        ]);
        $this->assertSame(200, $token['status'], $token['body']);
        $accessToken = json_decode($token['body'], true)['access_token'] ?? '';
        $this->assertNotSame('', $accessToken);

        $jwt = $this->request('GET', '/resources/jwt', headers: [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
        $this->assertSame(200, $jwt['status'], $jwt['body']);
        $authorizationJwt = json_decode($jwt['body'], true)['jwt'] ?? '';
        $this->assertNotSame('', $authorizationJwt);

        $userinfo = $this->request('GET', '/resources/userinfo', headers: [
            'Authorization' => 'Bearer ' . $authorizationJwt,
        ]);
        $this->assertSame(200, $userinfo['status'], $userinfo['body']);
        $payload = json_decode($userinfo['body'], true);
        $this->assertSame($this->userUuid, $payload['id'] ?? null);
        $this->assertSame(self::EMAIL, $payload['email'] ?? null);
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $headers
     * @return array{status: int, location: string, body: string}
     */
    private function request(string $method, string $path, array $form = [], array $headers = []): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($form !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withParsedBody($form);
        }

        $response = $this->app->handle($request);
        $location = $response->getHeaderLine('Location');
        if ($location !== '' && !str_starts_with($location, '/')) {
            $location = (string) parse_url($location, PHP_URL_PATH);
            $query = parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                $location .= '?' . $query;
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'location' => $location,
            'body' => (string) $response->getBody(),
        ];
    }

    private function queryValue(string $url, string $key): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return '';
        }
        parse_str($query, $params);

        return (string) ($params[$key] ?? '');
    }

    private function databaseIsReachable(): bool
    {
        try {
            $this->serverPdo()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function serverPdo(): PDO
    {
        $port = $_ENV['DB_PORT'] !== '' ? $_ENV['DB_PORT'] : '3306';

        return new PDO(
            sprintf('mysql:host=%s;port=%s', $_ENV['DB_HOST'], $port),
            (string) $_ENV['DB_USER'],
            (string) $_ENV['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function seedFixtures(): void
    {
        $pdo = $this->appPdo();
        $this->deleteFixtures();
        $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([self::EMAIL]);
        $pdo->prepare('DELETE FROM clients WHERE client_id = ?')->execute([self::CLIENT_ID]);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO users (email, first_name, last_name, user_id, username, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([self::EMAIL, 'Ork', 'Endpoint', $this->userUuid, 'ork-endpoint', $now, $now]);

        $pdo->prepare(
            'INSERT INTO clients (client_id, client_secret, name, redirect_uri, is_confidential, is_dev)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            self::CLIENT_ID,
            'endpoint-test-secret',
            'ORK endpoint test',
            json_encode([self::REDIRECT_URI]),
            0,
            0,
        ]);
    }

    private function deleteFixtures(): void
    {
        try {
            $pdo = $this->appPdo();
        } catch (\Throwable) {
            return;
        }

        $pdo->prepare('DELETE FROM user_client_authorizations WHERE user_identifier = ?')->execute([$this->userUuid]);
        $pdo->prepare('DELETE FROM user_jwt_generations WHERE user_uuid = ?')->execute([$this->userUuid]);
        $pdo->prepare('DELETE FROM auth_codes WHERE client_id = ?')->execute([self::CLIENT_ID]);
        $pdo->prepare('DELETE FROM access_tokens WHERE client_id = ?')->execute([self::CLIENT_ID]);
        $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([self::EMAIL]);
        $pdo->prepare('DELETE FROM clients WHERE client_id = ?')->execute([self::CLIENT_ID]);
    }

    private function appPdo(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                $_ENV['DB_HOST'],
                $_ENV['DB_PORT'] !== '' ? $_ENV['DB_PORT'] : '3306',
                $_ENV['DB_NAME']
            ),
            (string) $_ENV['DB_USER'],
            (string) $_ENV['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
