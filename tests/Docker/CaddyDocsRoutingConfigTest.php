<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Docker;

use PHPUnit\Framework\TestCase;

final class CaddyDocsRoutingConfigTest extends TestCase
{
    public function testDocsifyPathsReachPhpWorkerNotStaticFileServer(): void
    {
        $path = dirname(__DIR__, 2) . '/docker/Caddyfile';
        $config = file_get_contents($path);
        $this->assertIsString($config);

        // /docs and /docs/* are routed to the worker with the original URI
        // intact, so Slim sees /docs and serves the Docsify shell / markdown.
        $this->assertStringContainsString('@docs path /docs /docs/*', $config);
        $this->assertStringContainsString('handle @docs', $config);
        $this->assertStringContainsString('try_files /index.php{query}', $config);

        // A rewrite to /index.php loses the /docs URI and lets the static
        // handle serve the front controller's source instead of executing it.
        $this->assertStringNotContainsString('rewrite @docsExact /index.php', $config);
        $this->assertStringNotContainsString('handle_path /docs/*', $config);
    }

    public function testPhpFilesAreNeverServedAsStaticFiles(): void
    {
        $path = dirname(__DIR__, 2) . '/docker/Caddyfile';
        $config = file_get_contents($path);
        $this->assertIsString($config);

        // The static-file handle must exclude .php files, or Caddy serves
        // the front controller (and any .php file) as raw source.
        $this->assertStringContainsString('@static', $config);
        $this->assertStringContainsString('not path *.php', $config);
        $this->assertStringContainsString('file_server', $config);
    }
}
