<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Setup\HostEnv;

use Amtgard\IdP\Utility\Setup\HostEnv\InjectedSecretsLoader;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class InjectedSecretsLoaderTest extends TestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir() . '/idp-hev-loader-' . bin2hex(random_bytes(4));
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
            'VAULT_ONLY_KEY',
            'VALID_KEY',
            'ONLY_SUPER',
            'DELEGATED',
            'OAUTH_PRIVATE_KEY',
            'OAUTH_PUBLIC_KEY',
            'APPLE_KEY_FILE_PATH',
        ]);
        $this->rmTree($this->tmpdir);
    }

    public function testSkipLogsWhenSecretsEnvMissing(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        InjectedSecretsLoader::builder()->build()
            ->secretsDir($this->tmpdir . '/missing')
            ->logger($logger)
            ->applyMissing();

        $this->assertTrue($handler->hasDebugThatContains('host_env_inject_skip'));
    }

    public function testAppleKeyPathNotSetWhenDevFileAbsent(): void
    {
        $secretsDir = $this->tmpdir . '/no-apple';
        mkdir($secretsDir . '/files', 0700, true);
        file_put_contents($secretsDir . '/secrets.env', "VAULT_ONLY_KEY=only\n");
        file_put_contents($secretsDir . '/files/oauth-private.key', "priv\n");

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        InjectedSecretsLoader::builder()->build()
            ->secretsDir($secretsDir)
            ->logger($logger)
            ->applyMissing();

        $this->assertArrayNotHasKey('APPLE_KEY_FILE_PATH', $_ENV);
        $this->assertTrue($handler->hasInfoThatContains('host_env_inject_applied'));
    }

    public function testInjectedFilePathsDoNotOverrideExistingEnv(): void
    {
        $secretsDir = $this->tmpdir . '/preset';
        mkdir($secretsDir . '/files', 0700, true);
        file_put_contents($secretsDir . '/secrets.env', "VAULT_ONLY_KEY=x\n");
        file_put_contents($secretsDir . '/files/oauth-private.key', "vault-private\n");

        putenv('OAUTH_PRIVATE_KEY=/existing/private.key');
        $_ENV['OAUTH_PRIVATE_KEY'] = '/existing/private.key';

        InjectedSecretsLoader::builder()->build()
            ->secretsDir($secretsDir)
            ->applyMissing();

        $this->assertSame('/existing/private.key', $_ENV['OAUTH_PRIVATE_KEY']);
    }

    public function testApplyMissingReturnsEmptyWhenSecretsDirUnset(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $applied = InjectedSecretsLoader::builder()->build()
            ->secretsDir('')
            ->logger($logger)
            ->applyMissing();

        $this->assertSame([], $applied);
        $this->assertTrue($handler->hasDebugThatContains('host_env_inject_skip'));
    }

    public function testDotenvDoesNotOverrideExistingGetenvValue(): void
    {
        $secretsDir = $this->tmpdir . '/existing-env';
        mkdir($secretsDir, 0700, true);
        file_put_contents($secretsDir . '/secrets.env', "VAULT_ONLY_KEY=from-vault\n");

        putenv('VAULT_ONLY_KEY=from-shell');
        $_ENV['VAULT_ONLY_KEY'] = 'from-shell';

        $applied = InjectedSecretsLoader::builder()->build()
            ->secretsDir($secretsDir)
            ->applyMissing();

        $this->assertSame('from-shell', getenv('VAULT_ONLY_KEY'));
        $this->assertSame([], $applied);
    }

    public function testSecretsEnvIgnoresCommentsAndMalformedLines(): void
    {
        $secretsDir = $this->tmpdir . '/comments';
        mkdir($secretsDir, 0700, true);
        file_put_contents($secretsDir . '/secrets.env', "# comment\n\nMALFORMED\nVALID_KEY=ok\n");

        InjectedSecretsLoader::builder()->build()
            ->secretsDir($secretsDir)
            ->applyMissing();

        $this->assertSame('ok', $_ENV['VALID_KEY'] ?? '');
        $this->assertArrayNotHasKey('MALFORMED', $_ENV);
    }

    public function testOAuthPublicKeyPathAppliedWhenReadable(): void
    {
        $secretsDir = $this->tmpdir . '/public-key';
        mkdir($secretsDir . '/files', 0700, true);
        file_put_contents($secretsDir . '/secrets.env', "X=1\n");
        file_put_contents($secretsDir . '/files/oauth-public.key', "pub\n");

        $applied = InjectedSecretsLoader::builder()->build()
            ->secretsDir($secretsDir)
            ->applyMissing();

        $this->assertContains('OAUTH_PUBLIC_KEY', $applied);
        $this->assertSame($secretsDir . '/files/oauth-public.key', $_ENV['OAUTH_PUBLIC_KEY']);
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
