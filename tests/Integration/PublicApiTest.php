<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
use PHPUnit\Framework\TestCase;

/** Mode B — public API and management key routes without a session cookie. */
final class PublicApiTest extends TestCase
{
    private const ADMIN_EDIT_CLIENT = 'Idp:0::::IDP/EditClient';

    private IntegHttp $http;

    protected function setUp(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $this->http = new IntegHttp($baseUrl);
    }

    public function testOpenApiJsonIsPublic(): void
    {
        $response = $this->http->get('/openapi.json');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('openapi', $spec);
        $this->assertArrayHasKey('paths', $spec);
    }

    public function testIsAuthorizedAllowsMatchingPolicy(): void
    {
        $response = $this->http->postJson('/api/is_authorized', [
            'policy' => json_encode([self::ADMIN_EDIT_CLIENT], JSON_THROW_ON_ERROR),
            'requirement' => self::ADMIN_EDIT_CLIENT,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['is_authorized'] ?? false);
    }

    public function testIsAuthorizedDeniesEmptyPolicy(): void
    {
        $response = $this->http->postJson('/api/is_authorized', [
            'policy' => '[]',
            'requirement' => self::ADMIN_EDIT_CLIENT,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['is_authorized'] ?? true);
    }

    public function testCleanTokensWithOverlayKey(): void
    {
        $response = $this->http->get(
            '/management/cleantokens?key=' . rawurlencode(IntegFixtures::MANAGEMENT_KEY),
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('Tokens cleaned successfully', (string) $response->getBody());
    }

    public function testCleanTokensRejectsWrongKey(): void
    {
        $response = $this->http->get('/management/cleantokens?key=wrong-management-key-value');

        $this->assertContains($response->getStatusCode(), [401, 403], (string) $response->getBody());
    }
}
