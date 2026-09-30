<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
/** Mode A — admin management UI and operator redirect update; player denied admin routes. */
final class ManagementUiTest extends IntegTestCase
{
    private const MANAGEMENT_CLIENTS_PATH = '/management/clients';

    public function testAdminClientsCreateRedirectSearchAndAccess(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);
        $this->login($http, IntegFixtures::ADMIN_EMAIL);

        $listPage = $http->get(self::MANAGEMENT_CLIENTS_PATH);
        $this->assertSame(200, $listPage->getStatusCode(), (string) $listPage->getBody());
        $listHtml = (string) $listPage->getBody();
        $this->assertStringContainsString('Manage Clients', $listHtml);
        $csrf = $http->parseCsrfToken($listHtml);

        $createResponse = $http->postForm('/management/clients', [
            '_csrf_token' => $csrf,
            'name' => 'Integration UI client',
            'client_id' => IntegFixtures::UI_CREATED_CLIENT_ID,
            'client_secret' => 'integ-from-ui-secret',
            'redirect_uri' => IntegFixtures::UI_CREATED_REDIRECT_URI,
            'is_confidential' => '1',
            'is_dev' => '1',
            'iam_service' => 'IntegFromUi',
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($createResponse, self::MANAGEMENT_CLIENTS_PATH),
            'Expected 302 to client list after create; location=' . $createResponse->getHeaderLine('Location'),
        );

        $afterCreate = $http->get(self::MANAGEMENT_CLIENTS_PATH);
        $this->assertSame(200, $afterCreate->getStatusCode());
        $afterCreateHtml = (string) $afterCreate->getBody();
        $this->assertStringContainsString(IntegFixtures::UI_CREATED_CLIENT_ID, $afterCreateHtml);
        $clientDbId = $this->parseClientDbId($afterCreateHtml, IntegFixtures::UI_CREATED_CLIENT_ID);
        $csrf = $http->parseCsrfToken($afterCreateHtml);

        $selfAccess = $http->postForm('/management/clients/' . $clientDbId . '/access', [
            '_csrf_token' => $csrf,
            'email' => IntegFixtures::ADMIN_EMAIL,
        ]);
        $this->assertSame(200, $selfAccess->getStatusCode(), (string) $selfAccess->getBody());

        $redirectResponse = $http->postForm('/resources/clients/' . $clientDbId . '/redirect', [
            '_csrf_token' => $csrf,
            'redirect_uri' => IntegFixtures::UI_UPDATED_REDIRECT_URI,
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($redirectResponse, '/resources/clients'),
            'Expected 302 to operator client list after redirect update; location='
            . $redirectResponse->getHeaderLine('Location'),
        );

        $afterRedirect = $http->get(self::MANAGEMENT_CLIENTS_PATH);
        $this->assertSame(200, $afterRedirect->getStatusCode());
        $afterRedirectHtml = (string) $afterRedirect->getBody();
        $this->assertSame(
            IntegFixtures::UI_UPDATED_REDIRECT_URI,
            $this->parseClientRedirectUri($afterRedirectHtml, IntegFixtures::UI_CREATED_CLIENT_ID),
        );

        $searchResponse = $http->get('/management/users/search?q=integ');
        $this->assertSame(200, $searchResponse->getStatusCode(), (string) $searchResponse->getBody());
        /** @var array<string, mixed> $searchPayload */
        $searchPayload = json_decode((string) $searchResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $users = $searchPayload['users'] ?? [];
        $this->assertIsArray($users);
        $emails = array_map(
            static fn (array $row): string => (string) ($row['email'] ?? ''),
            $users,
        );
        $this->assertContains(IntegFixtures::PLAYER_EMAIL, $emails);

        $grantResponse = $http->postForm('/management/clients/' . $clientDbId . '/access', [
            '_csrf_token' => $csrf,
            'email' => IntegFixtures::PLAYER_EMAIL,
        ]);
        $this->assertSame(200, $grantResponse->getStatusCode(), (string) $grantResponse->getBody());
        /** @var array<string, mixed> $grantPayload */
        $grantPayload = json_decode((string) $grantResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $playerUserId = (int) ($grantPayload['id'] ?? 0);
        $this->assertGreaterThan(0, $playerUserId);
        $this->assertSame(IntegFixtures::PLAYER_EMAIL, $grantPayload['email'] ?? null);

        $deleteResponse = $http->postForm(
            '/management/clients/' . $clientDbId . '/access/' . $playerUserId . '/delete',
            ['_csrf_token' => $csrf],
        );
        $this->assertSame(200, $deleteResponse->getStatusCode(), (string) $deleteResponse->getBody());
        /** @var array<string, mixed> $deletePayload */
        $deletePayload = json_decode((string) $deleteResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($deletePayload['ok'] ?? false);
    }

    public function testPlayerCannotOpenManagementClients(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);
        $this->login($http, IntegFixtures::PLAYER_EMAIL);

        $response = $http->get(self::MANAGEMENT_CLIENTS_PATH);
        $this->assertNotSame(200, $response->getStatusCode(), 'Player must not receive management HTML');
    }

    private function login(IntegHttp $http, string $email): void
    {
        $loginPage = $http->get('/auth/login?expand=1');
        $this->assertSame(200, $loginPage->getStatusCode(), (string) $loginPage->getBody());
        $loginCsrf = $http->parseCsrfToken((string) $loginPage->getBody());

        $loginResponse = $http->postForm('/auth/login', [
            '_csrf_token' => $loginCsrf,
            'email' => $email,
            'password' => IntegFixtures::PASSWORD,
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($loginResponse, '/resources/profile'),
            'Expected login redirect to profile; status=' . $loginResponse->getStatusCode()
            . ' location=' . $loginResponse->getHeaderLine('Location'),
        );
    }

    private function parseClientDbId(string $html, string $identifier): int
    {
        preg_match_all('/data-client="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $encoded) {
            $json = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            /** @var array<string, mixed>|null $client */
            $client = json_decode($json, true);
            if (!is_array($client)) {
                continue;
            }
            if (($client['identifier'] ?? '') === $identifier) {
                $id = (int) ($client['id'] ?? 0);
                if ($id > 0) {
                    return $id;
                }
            }
        }

        throw new \RuntimeException("Client {$identifier} not found in management HTML");
    }

    private function parseClientRedirectUri(string $html, string $identifier): string
    {
        preg_match_all('/data-client="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $encoded) {
            $json = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            /** @var array<string, mixed>|null $client */
            $client = json_decode($json, true);
            if (!is_array($client)) {
                continue;
            }
            if (($client['identifier'] ?? '') === $identifier) {
                return (string) ($client['redirectUri'] ?? '');
            }
        }

        throw new \RuntimeException("Redirect URI for client {$identifier} not found in management HTML");
    }
}
