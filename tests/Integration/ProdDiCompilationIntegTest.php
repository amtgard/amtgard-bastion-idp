<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/**
 * PROD PHP-DI compilation is validated in the app container via CLI (FPM stays DEV_INTEG for the rest of integ).
 */
final class ProdDiCompilationIntegTest extends IntegTestCase
{
    private const DEFAULT_APP_CONTAINER = 'amtgard-idp';

    public function testIntegHttpStackStillServesWhileProdCliCompilesContainer(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $response = $http->get('/version');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testProdEnvironmentBuildsCompiledContainerInsideAppContainer(): void
    {
        $appContainer = (string) (getenv('APP_CONTAINER') ?: self::DEFAULT_APP_CONTAINER);
        $inner = 'cd /var/www/idp.amtgard.com && ENVIRONMENT=PROD php tests/Integration/verify_prod_di_container.php';
        $command = sprintf(
            'docker exec %s bash -lc %s 2>&1',
            escapeshellarg($appContainer),
            escapeshellarg($inner),
        );

        exec($command, $output, $exitCode);
        $joined = implode("\n", $output);

        $this->assertSame(
            0,
            $exitCode,
            "verify_prod_di_container.php failed (is {$appContainer} running?):\n{$joined}",
        );
        $this->assertStringContainsString('prod_di_container:ok', $joined);
    }
}
