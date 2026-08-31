<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\BuildInfo;
use PHPUnit\Framework\TestCase;

class BuildInfoTest extends TestCase
{
    public function testLoadReadsCommittedVersionFiles(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root . '/VERSION');
        $this->assertFileExists($root . '/version.json');

        $info = BuildInfo::load();

        $this->assertArrayHasKey('version', $info);
        $this->assertArrayHasKey('build_id', $info);
        $this->assertNotSame('unknown', $info['version']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}\.\d+\+[0-9a-f]+$/',
            $info['version']
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}\.\d+$/',
            $info['build_id']
        );
        $this->assertNotEmpty($info['short_commit']);
    }

    public function testGetVersionMatchesLoad(): void
    {
        $this->assertSame(BuildInfo::load()['version'], BuildInfo::getVersion());
    }
}
