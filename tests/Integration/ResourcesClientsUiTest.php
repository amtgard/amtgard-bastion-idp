<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode A — logged-in player operator GET /resources/clients. */
final class ResourcesClientsUiTest extends IntegTestCase
{
    private const MANAGEMENT_CLIENTS_PATH = '/management/clients';

    public function testPlayerResourcesClientsListsGrantedClientsOnly(): void
    {
        $baseUrl = $this->integBaseUrl();
        $admin = new IntegHttp($baseUrl);
        $this->login($admin, IntegFixtures::ADMIN_EMAIL);

        $listPage = $admin->get(self::MANAGEMENT_CLIENTS_PATH);
        $this->assertSame(200, $listPage->getStatusCode(), (string) $listPage->getBody());
        $listHtml = (string) $listPage->getBody();
        $csrf = $admin->parseCsrfToken($listHtml);

        $createResponse = $admin->postForm(self::MANAGEMENT_CLIENTS_PATH, [
            '_csrf_token' => $csrf,
            'name' => 'Player operator integ client',
            'client_id' => IntegFixtures::PLAYER_OPERATOR_CLIENT_ID,
            'client_secret' => 'integ-player-operator-secret',
            'redirect_uri' => IntegFixtures::PLAYER_OPERATOR_REDIRECT_URI,
            'is_confidential' => '1',
            'is_dev' => '1',
            'iam_service' => 'IntegPlayerOperator',
        ]);
        $this->assertTrue(
            $admin->isRedirectToPath($createResponse, self::MANAGEMENT_CLIENTS_PATH),
            'Expected redirect to client list after create; location=' . $createResponse->getHeaderLine('Location'),
        );

        $afterCreate = $admin->get(self::MANAGEMENT_CLIENTS_PATH);
        $this->assertSame(200, $afterCreate->getStatusCode());
        $afterCreateHtml = (string) $afterCreate->getBody();
        $clientDbId = $this->parseClientDbId($afterCreateHtml, IntegFixtures::PLAYER_OPERATOR_CLIENT_ID);
        $csrf = $admin->parseCsrfToken($afterCreateHtml);

        $grantResponse = $admin->postForm('/management/clients/' . $clientDbId . '/access', [
            '_csrf_token' => $csrf,
            'email' => IntegFixtures::PLAYER_EMAIL,
        ]);
        $this->assertSame(200, $grantResponse->getStatusCode(), (string) $grantResponse->getBody());

        $player = new IntegHttp($baseUrl);
        $this->login($player, IntegFixtures::PLAYER_EMAIL);

        $operatorList = $player->get('/resources/clients');
        $this->assertSame(200, $operatorList->getStatusCode(), (string) $operatorList->getBody());
        $operatorHtml = (string) $operatorList->getBody();
        $this->assertStringContainsString('>Clients</h1>', $operatorHtml);
        $this->assertStringNotContainsString('Manage Clients', $operatorHtml);
        $this->assertStringContainsString(IntegFixtures::PLAYER_OPERATOR_CLIENT_ID, $operatorHtml);
        $this->assertStringNotContainsString(IntegFixtures::CONFIDENTIAL_CLIENT_ID, $operatorHtml);
        $this->assertStringContainsString('const viewMode = "operator"', $operatorHtml);
        $this->assertStringNotContainsString('bg-green-500 hover:bg-green-600', $operatorHtml);
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
}
