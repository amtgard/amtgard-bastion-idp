<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Setup\HostEnv;

use Amtgard\IdP\Utility\Setup\HostEnv\HostEnvBootstrap;
use Amtgard\IdP\Utility\Setup\HostEnv\InjectedSecretsLoader;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class HostEnvBootstrapTest extends TestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir() . '/idp-hev-' . bin2hex(random_bytes(4));
        mkdir($this->tmpdir, 0700, true);
        $this->unsetEnv([
            'OAUTH_PRIVATE_KEY',
            'OAUTH_PUBLIC_KEY',
            'APPLE_KEY_FILE_PATH',
        ]);
    }

    protected function tearDown(): void
    {
        $this->unsetEnv([
            'HOST_ENV_SECRETS_DIR',
            'VAULT_ONLY_KEY',
            'ONLY_SUPER',
            'DELEGATED',
            'OAUTH_PRIVATE_KEY',
            'OAUTH_PUBLIC_KEY',
            'APPLE_KEY_FILE_PATH',
        ]);
        $this->rmTree($this->tmpdir);
    }

    public function testApplyFromProcessEnvIsNoOpWhenSecretsDirUnset(): void
    {
        $this->unsetEnv(['HOST_ENV_SECRETS_DIR']);

        HostEnvBootstrap::builder()->build()->applyFromProcessEnv();

        $this->assertSame('', getenv('HOST_ENV_SECRETS_DIR') ?: '');
    }

    public function testApplyFromProcessEnvReadsSecretsDirFromSuperglobalWhenGetenvEmpty(): void
    {
        $secretsDir = $this->tmpdir . '/superglobal';
        mkdir($secretsDir, 0700, true);
        file_put_contents($secretsDir . '/secrets.env', "ONLY_SUPER=1\n");

        putenv('HOST_ENV_SECRETS_DIR');
        $_ENV['HOST_ENV_SECRETS_DIR'] = $secretsDir;

        HostEnvBootstrap::builder()->build()->applyFromProcessEnv();

        $this->assertSame('1', $_ENV['ONLY_SUPER'] ?? '');
    }

    public function testApplyFromProcessEnvUsesInjectedLoader(): void
    {
        $secretsDir = $this->tmpdir . '/delegated';
        mkdir($secretsDir, 0700, true);
        file_put_contents($secretsDir . '/secrets.env', "DELEGATED=1\n");

        putenv('HOST_ENV_SECRETS_DIR=' . $secretsDir);
        $_ENV['HOST_ENV_SECRETS_DIR'] = $secretsDir;

        $loader = InjectedSecretsLoader::builder()->build();
        $bootstrap = HostEnvBootstrap::builder()->build()->injectLoader($loader);

        $bootstrap->applyFromProcessEnv();

        $this->assertSame('1', $_ENV['DELEGATED'] ?? '');
    }

    public function testInjectAppliesMissingKeysViaDotenvAndOAuthFilePathsAndLogs(): void
    {
        $secretsDir = $this->tmpdir . '/http';
        mkdir($secretsDir . '/files', 0700, true);
        file_put_contents($secretsDir . '/secrets.env', "VAULT_ONLY_KEY=from-vault\n");
        file_put_contents($secretsDir . '/files/oauth-private.key', "private-bytes\n");
        file_put_contents($secretsDir . '/files/oauth-public.key', "public-bytes\n");
        file_put_contents($secretsDir . '/files/apple.p8', "apple-bytes\n");

        putenv('HOST_ENV_SECRETS_DIR=' . $secretsDir);
        $_ENV['HOST_ENV_SECRETS_DIR'] = $secretsDir;

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        HostEnvBootstrap::builder()->build()->logger($logger)->applyFromProcessEnv();

        $this->assertSame('from-vault', $_ENV['VAULT_ONLY_KEY'] ?? '');
        $this->assertSame($secretsDir . '/files/oauth-private.key', $_ENV['OAUTH_PRIVATE_KEY'] ?? '');
        $this->assertSame($secretsDir . '/files/oauth-public.key', $_ENV['OAUTH_PUBLIC_KEY'] ?? '');
        $this->assertSame($secretsDir . '/files/apple.p8', $_ENV['APPLE_KEY_FILE_PATH'] ?? '');
        $this->assertTrue($handler->hasInfoThatContains('host_env_inject_applied'));
    }

    /**
     * @param list<string> $keys
     */
    private function unsetEnv(array $keys): void
    {
        foreach ($keys as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
