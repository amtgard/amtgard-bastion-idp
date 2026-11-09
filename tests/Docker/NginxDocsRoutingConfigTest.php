<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Docker;

use PHPUnit\Framework\TestCase;

final class NginxDocsRoutingConfigTest extends TestCase
{
    public function testDocsifyPathsBypassStaticDirectoryServing(): void
    {
        $path = dirname(__DIR__, 2) . '/docker/nginx.idp.config';
        $config = file_get_contents($path);
        $this->assertIsString($config);

        $this->assertStringContainsString('location = /docs {', $config);
        $this->assertStringContainsString('location = /docs/ {', $config);
        $this->assertStringContainsString('location ^~ /docs/ {', $config);
        $this->assertStringContainsString('rewrite ^ /index.php?$query_string last;', $config);
    }
}
