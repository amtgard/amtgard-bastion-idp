<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Guards bootstrap wiring when vault load-env.php is merged alongside di-container.php (same ENV helpers).
 */
final class BootstrapConfigLoadTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = dirname(__DIR__, 2) . '/config';
    }

    public function testDiContainerAndEnvironmentHelpersLoadWithoutRedeclare(): void
    {
        require_once $this->configDir . '/idp-environment.php';
        require_once $this->configDir . '/di-container.php';
        require_once $this->configDir . '/idp-environment.php';
        require_once $this->configDir . '/di-container.php';

        $this->assertTrue(function_exists('idp_runtime_environment'));
        $this->assertTrue(function_exists('idp_is_prod_environment'));
        $this->assertTrue(function_exists('idp_build_di_container'));
    }

    public function testBootstrapPhpLoadsOnceWithoutFatalError(): void
    {
        putenv('ENVIRONMENT=DEV');
        $_ENV['ENVIRONMENT'] = 'DEV';

        $container = require $this->configDir . '/bootstrap.php';

        $this->assertNotSame('', $container::class);
    }

    public function testDiContainerLoadsAfterLoadEnvStyleFixtureWithoutRedeclare(): void
    {
        require_once $this->configDir . '/di-container.php';
        require_once dirname(__DIR__) . '/fixtures/config/load-env-without-env-redeclare.php';

        $this->assertTrue(function_exists('idp_load_process_env_fixture'));
        $this->assertTrue(function_exists('idp_build_di_container'));
    }
}
