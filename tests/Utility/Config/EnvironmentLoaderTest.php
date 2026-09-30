<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Config;

use Amtgard\IdP\Utility\Config\EnvironmentLoader;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class EnvironmentLoaderTest extends TestCase
{
    private string $includesDir;

    protected function setUp(): void
    {
        $this->includesDir = sys_get_temp_dir() . '/idp-env-loader-' . uniqid('', true);
        mkdir($this->includesDir);
        touch($this->includesDir . '/live.php');
        touch($this->includesDir . '/integ.php');
    }

    protected function tearDown(): void
    {
        @unlink($this->includesDir . '/live.php');
        @unlink($this->includesDir . '/integ.php');
        @rmdir($this->includesDir);
    }

    public function testEmitReturnsLiveIncludeForDevEnvironment(): void
    {
        $loader = $this->loader('DEV');
        $loader->register('demo', 'live.php', 'integ.php');

        $this->assertSame($this->includesDir . '/live.php', $loader->emit('demo'));
        $this->assertFalse($loader->isInteg());
    }

    public function testEmitUsesIntegIncludeWhenRegisteredAndEnvironmentMatches(): void
    {
        $loader = $this->loader('DEV_INTEG');
        $loader->register('demo', 'live.php', 'integ.php');

        $this->assertTrue($loader->isInteg());
        $this->assertSame($this->includesDir . '/integ.php', $loader->emit('demo'));
    }

    public function testEmitFallsBackToLiveWhenIntegFileIsNull(): void
    {
        $loader = $this->loader('DEV_INTEG');
        $loader->register('demo', 'live.php', null);

        $this->assertSame($this->includesDir . '/live.php', $loader->emit('demo'));
    }

    public function testEmitThrowsForUnknownModule(): void
    {
        $loader = $this->loader('DEV');

        $this->expectException(\InvalidArgumentException::class);
        $loader->emit('missing');
    }

    public function testEmitLogsIncludeSelectionWhenLoggerConfigured(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $loader = EnvironmentLoader::builder()
            ->liveIncludesDir($this->includesDir)
            ->integIncludesDir($this->includesDir)
            ->environment('DEV_INTEG')
            ->integEnvironment('DEV_INTEG')
            ->logger($logger)
            ->build();
        $loader->register('demo', 'live.php', 'integ.php');
        $loader->emit('demo');

        $this->assertTrue($handler->hasDebugRecords());
        $record = $handler->getRecords()[0];
        $this->assertSame('EnvironmentLoader selected container include', $record['message']);
        $this->assertSame('demo', $record['context']['module']);
        $this->assertTrue($record['context']['integ']);
    }

    private function loader(string $environment): EnvironmentLoader
    {
        return EnvironmentLoader::builder()
            ->liveIncludesDir($this->includesDir)
            ->integIncludesDir($this->includesDir)
            ->environment($environment)
            ->integEnvironment('DEV_INTEG')
            ->build();
    }
}
