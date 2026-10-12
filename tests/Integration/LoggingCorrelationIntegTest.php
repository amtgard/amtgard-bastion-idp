<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode B — correlation + L4 HTTP observability on live stack (`X-Request-Id` on responses). */
final class LoggingCorrelationIntegTest extends IntegTestCase
{
    public function testVersionEchoesValidClientRequestId(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $clientRequestId = 'client-req-12345678';

        $response = $http->getWithHeaders('/version', [
            'X-Request-Id' => $clientRequestId,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame($clientRequestId, $response->getHeaderLine('X-Request-Id'));
    }

    public function testVersionGeneratesRequestIdWhenHeaderMissing(): void
    {
        $http = new IntegHttp($this->integBaseUrl());

        $response = $http->get('/version');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $requestId = $response->getHeaderLine('X-Request-Id');
        $this->assertNotSame('', $requestId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $requestId);
    }

    public function testValidateRejectCarriesClientRequestId(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $clientRequestId = 'integ-validate-reject-01';

        $response = $http->getWithHeaders('/resources/validate', [
            'X-Request-Id' => $clientRequestId,
        ]);

        $this->assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame($clientRequestId, $response->getHeaderLine('X-Request-Id'));
    }

    public function testOAuthTokenErrorCarriesClientRequestId(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $clientRequestId = 'integ-oauth-token-reject-01';

        $response = $http->postFormWithBasicAuth('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => 'integ-not-a-real-authorization-code',
            'redirect_uri' => IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
        ], IntegFixtures::CONFIDENTIAL_CLIENT_ID, IntegFixtures::CONFIDENTIAL_CLIENT_SECRET, [
            'X-Request-Id' => $clientRequestId,
        ]);

        $this->assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame($clientRequestId, $response->getHeaderLine('X-Request-Id'));
    }
}
