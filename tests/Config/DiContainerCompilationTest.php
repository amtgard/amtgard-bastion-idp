<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;

final class DiContainerCompilationTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/config/di-container.php';

        $this->projectRoot = dirname(__DIR__, 2);
        putenv('ENVIRONMENT');
        unset($_ENV['ENVIRONMENT'], $_SERVER['ENVIRONMENT']);
    }

    protected function tearDown(): void
    {
        putenv('ENVIRONMENT');
        unset($_ENV['ENVIRONMENT'], $_SERVER['ENVIRONMENT']);
        parent::tearDown();
    }

    public function testProdEnvironmentIsDetectedCaseInsensitively(): void
    {
        $_ENV['ENVIRONMENT'] = 'prod';
        $this->assertTrue(idp_is_prod_environment());

        $_ENV['ENVIRONMENT'] = 'PROD';
        $this->assertTrue(idp_is_prod_environment());
    }

    public function testNonProdEnvironmentsDoNotEnableCompilation(): void
    {
        foreach (['DEV', 'DEV_INTEG', ''] as $environment) {
            if ($environment === '') {
                unset($_ENV['ENVIRONMENT'], $_SERVER['ENVIRONMENT']);
            } else {
                $_ENV['ENVIRONMENT'] = $environment;
            }

            $builder = new ContainerBuilder();
            idp_configure_di_container_builder($builder, $this->projectRoot);

            $this->assertFalse(
                $builder->isCompilationEnabled(),
                'Expected compilation off for ENVIRONMENT=' . ($environment === '' ? '<unset>' : $environment),
            );
        }
    }

    public function testProdEnvironmentEnablesCompilationIntoCacheDi(): void
    {
        $_ENV['ENVIRONMENT'] = 'PROD';

        $builder = new ContainerBuilder();
        idp_configure_di_container_builder($builder, $this->projectRoot);

        $this->assertTrue($builder->isCompilationEnabled());
        $this->assertSame(
            $this->projectRoot . '/cache/di',
            idp_di_compilation_directory($this->projectRoot),
        );
        $this->assertDirectoryExists(idp_di_compilation_directory($this->projectRoot));
    }

    public function testCompileDirectoryOverrideViaEnv(): void
    {
        $override = $this->projectRoot . '/cache/di-unit-override';
        putenv('IDP_DI_COMPILE_DIR=' . $override);
        $_ENV['ENVIRONMENT'] = 'PROD';

        try {
            $this->assertSame($override, idp_di_compilation_directory($this->projectRoot));

            $builder = new ContainerBuilder();
            idp_configure_di_container_builder($builder, $this->projectRoot);
            $this->assertTrue($builder->isCompilationEnabled());
        } finally {
            putenv('IDP_DI_COMPILE_DIR');
            if (is_dir($override)) {
                foreach (glob($override . '/*') ?: [] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
                rmdir($override);
            }
        }
    }
}
