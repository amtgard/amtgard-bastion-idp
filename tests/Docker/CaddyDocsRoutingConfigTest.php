<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Docker;

use PHPUnit\Framework\TestCase;

final class CaddyDocsRoutingConfigTest extends TestCase
{
    public function testDocsifyPathsBypassStaticDirectoryServing(): void
    {
        $path = dirname(__DIR__, 2) . '/docker/Caddyfile';
        $config = file_get_contents($path);
        $this->assertIsString($config);

        $this->assertStringContainsString('@docsExact path /docs /docs/', $config);
        $this->assertStringContainsString('rewrite @docsExact /index.php{query}', $config);
        $this->assertStringContainsString('handle_path /docs/*', $config);
        $this->assertStringContainsString('try_files {path} /index.php{query}', $config);
    }
}
