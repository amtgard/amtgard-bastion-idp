<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode B — `CorrelationMiddleware` echoes or generates `X-Request-Id` on HTTP responses. */
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
}
