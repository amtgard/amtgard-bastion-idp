<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Ensures PHP bootstrap completes (no fatal redeclare) before HTML is served. */
final class BootstrapHttpIntegTest extends IntegTestCase
{
    public function testHomePageDoesNotExposeBootstrapFatalErrors(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $response = $http->get('/');

        $body = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode(), $body);
        $this->assertStringNotContainsString('Cannot redeclare function idp_runtime_environment', $body);
        $this->assertStringNotContainsString('Fatal error', $body);
    }
}
