<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use PHPUnit\Framework\TestCase;

final class LoadEnvTest extends TestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/config/load-env.php';

        $this->tmpdir = sys_get_temp_dir() . '/idp-load-env-' . bin2hex(random_bytes(4));
        mkdir($this->tmpdir, 0700, true);

        putenv('DB_PASS');
        putenv('GOOGLE_CLIENT_SECRET');
        putenv('ENVIRONMENT');
        unset(
            $_ENV['DB_PASS'],
            $_ENV['GOOGLE_CLIENT_SECRET'],
            $_ENV['ENVIRONMENT'],
            $_SERVER['ENVIRONMENT'],
        );
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpdir);
        putenv('DB_PASS');
        putenv('ENVIRONMENT');
        putenv('IDP_INJECT_SECRETS_DIR');
        unset($_ENV['DB_PASS'], $_ENV['ENVIRONMENT'], $_ENV['GOOGLE_CLIENT_SECRET']);
        putenv('GOOGLE_CLIENT_SECRET');
    }

    public function testDevDotenvKeysSurviveInjectOverride(): void
    {
        $injectDir = $this->tmpdir . '/inject';
        mkdir($injectDir, 0700, true);
        file_put_contents(
            $this->tmpdir . '/.env',
            "ENVIRONMENT=DEV\nDB_PASS=secret\nGOOGLE_CLIENT_SECRET=from-app\n",
        );
        file_put_contents(
            $injectDir . '/secrets.env',
            "DB_PASS=dev-idp-db-pass\nGOOGLE_CLIENT_SECRET=from-inject\n",
        );
        putenv('IDP_INJECT_SECRETS_DIR=' . $injectDir);

        idp_load_process_env($this->tmpdir);

        $this->assertSame('secret', $_ENV['DB_PASS'] ?? '');
        $this->assertSame('from-app', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
    }

    public function testDevDatabaseCredentialsSurviveInjectOverride(): void
    {
        $injectDir = $this->tmpdir . '/inject';
        mkdir($injectDir, 0700, true);
        file_put_contents($this->tmpdir . '/.env', "ENVIRONMENT=DEV\nDB_PASS=secret\n");
        file_put_contents($injectDir . '/secrets.env', "DB_PASS=dev-idp-db-pass\n");
        putenv('IDP_INJECT_SECRETS_DIR=' . $injectDir);

        idp_load_process_env($this->tmpdir);

        $this->assertSame('secret', $_ENV['DB_PASS'] ?? '');
    }

    public function testInjectFilePathsApplyWhenEnvKeyMissing(): void
    {
        $injectDir = $this->tmpdir . '/inject';
        $filesDir = $injectDir . '/files';
        mkdir($filesDir, 0700, true);
        file_put_contents($injectDir . '/secrets.env', "DB_PASS=from-inject\n");
        file_put_contents($filesDir . '/oauth-private.key', "fake-pem");
        putenv('IDP_INJECT_SECRETS_DIR=' . $injectDir);
        putenv('ENVIRONMENT=PROD');
        putenv('OAUTH_PRIVATE_KEY');
        unset($_ENV['OAUTH_PRIVATE_KEY'], $_SERVER['OAUTH_PRIVATE_KEY']);
        $_SERVER['ENVIRONMENT'] = 'PROD';

        idp_load_process_env($this->tmpdir);

        $this->assertSame($filesDir . '/oauth-private.key', $_ENV['OAUTH_PRIVATE_KEY'] ?? '');
    }

    public function testDevIntegSkipsVaultInject(): void
    {
        $injectDir = $this->tmpdir . '/inject';
        mkdir($injectDir, 0700, true);
        file_put_contents($this->tmpdir . '/.env', "DB_PASS=secret\n");
        file_put_contents($injectDir . '/secrets.env', "DB_PASS=dev-idp-db-pass\n");
        putenv('IDP_INJECT_SECRETS_DIR=' . $injectDir);
        putenv('ENVIRONMENT=DEV_INTEG');
        putenv('DB_PASS');
        unset($_ENV['DB_PASS'], $_SERVER['DB_PASS']);
        $_SERVER['ENVIRONMENT'] = 'DEV_INTEG';

        idp_load_process_env($this->tmpdir);

        $this->assertSame('secret', $_ENV['DB_PASS'] ?? '');
    }

    public function testDevIntegClearsLocalOrkJsonApiOverrideFromDotenv(): void
    {
        file_put_contents(
            $this->tmpdir . '/.env',
            "ORK_JSON_API_BASE_URL=http://ork3-php8-app/orkservice/Json/index.php\nORK_CLAIM_MAIL_VIA_ORK=1\n",
        );
        putenv('ENVIRONMENT=DEV_INTEG');
        $_SERVER['ENVIRONMENT'] = 'DEV_INTEG';

        idp_load_process_env($this->tmpdir);

        $this->assertArrayNotHasKey('ORK_JSON_API_BASE_URL', $_ENV);
        $this->assertFalse(getenv('ORK_JSON_API_BASE_URL'));
        $this->assertArrayNotHasKey('ORK_CLAIM_MAIL_VIA_ORK', $_ENV);
        $this->assertFalse(getenv('ORK_CLAIM_MAIL_VIA_ORK'));
    }

    public function testNonDevInjectOverridesApplicationDatabasePassword(): void
    {
        $injectDir = $this->tmpdir . '/inject';
        mkdir($injectDir, 0700, true);
        file_put_contents($this->tmpdir . '/.env', "ENVIRONMENT=PROD\nDB_PASS=from-app\n");
        file_put_contents($injectDir . '/secrets.env', "DB_PASS=from-inject\n");
        putenv('IDP_INJECT_SECRETS_DIR=' . $injectDir);

        idp_load_process_env($this->tmpdir);

        $this->assertSame('from-inject', $_ENV['DB_PASS'] ?? '');
    }

    public function testMutableInjectOverridesApplicationDotenv(): void
    {
        file_put_contents($this->tmpdir . '/.env', "DB_PASS=from-app\n");
        file_put_contents($this->tmpdir . '/secrets.env', "DB_PASS=from-inject\n");

        \Dotenv\Dotenv::createImmutable($this->tmpdir)->safeLoad();
        \Dotenv\Dotenv::createMutable($this->tmpdir, 'secrets.env')->load();

        $this->assertSame('from-inject', $_ENV['DB_PASS'] ?? '');
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
