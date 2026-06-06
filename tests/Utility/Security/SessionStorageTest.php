<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Security;

use Amtgard\IdP\Tests\Support\ResetsPhpSessionState;
use Amtgard\IdP\Utility\Security\SessionStorage;
use PHPUnit\Framework\TestCase;

class SessionStorageTest extends TestCase
{
    use ResetsPhpSessionState;

    protected function setUp(): void
    {
        $this->captureSessionIniState();
        $this->resetPhpSessionState();
        unset($_ENV['SESSION_REDIS_HOST'], $_ENV['SESSION_REDIS_PORT'], $_ENV['SESSION_REDIS_DB'], $_ENV['SESSION_REDIS_PREFIX']);
    }

    protected function tearDown(): void
    {
        $this->restoreSessionIniState();
        unset(
            $_ENV['SESSION_REDIS_HOST'],
            $_ENV['SESSION_REDIS_PORT'],
            $_ENV['SESSION_REDIS_DB'],
            $_ENV['SESSION_REDIS_PREFIX']
        );
    }

    public function testConfigureUsesRedisWhenHostSet(): void
    {
        $_ENV['SESSION_REDIS_HOST'] = 'amtgard-idp-sessions';
        $_ENV['SESSION_REDIS_PORT'] = '6379';
        $_ENV['SESSION_REDIS_DB'] = '1';
        $_ENV['SESSION_REDIS_PREFIX'] = 'PHPSESS:';

        SessionStorage::configure();

        $this->assertSame('redis', ini_get('session.save_handler'));
        $this->assertSame(
            'tcp://amtgard-idp-sessions:6379?database=1&prefix=PHPSESS:',
            ini_get('session.save_path')
        );
    }

    public function testConfigureLeavesHandlerUnsetWhenHostMissing(): void
    {
        $handlerBefore = ini_get('session.save_handler');

        SessionStorage::configure();

        $this->assertSame($handlerBefore, ini_get('session.save_handler'));
    }

    public function testConfigureUsesDefaultsForOptionalSessionValues(): void
    {
        $_ENV['SESSION_REDIS_HOST'] = 'amtgard-idp-sessions';

        SessionStorage::configure();

        $this->assertSame(
            'tcp://amtgard-idp-sessions:6379?database=1&prefix=PHPSESS:',
            ini_get('session.save_path')
        );
    }

    public function testConfigureTrimsHostWhitespace(): void
    {
        $_ENV['SESSION_REDIS_HOST'] = '  amtgard-idp-sessions  ';

        SessionStorage::configure();

        $this->assertStringContainsString('tcp://amtgard-idp-sessions:6379', ini_get('session.save_path'));
    }

    public function testConfigureUsesCustomPrefix(): void
    {
        $_ENV['SESSION_REDIS_HOST'] = 'amtgard-idp-sessions';
        $_ENV['SESSION_REDIS_PREFIX'] = 'IDPSESS:';

        SessionStorage::configure();

        $this->assertSame(
            'tcp://amtgard-idp-sessions:6379?database=1&prefix=IDPSESS:',
            ini_get('session.save_path')
        );
    }
}
