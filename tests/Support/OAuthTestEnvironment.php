<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

/**
 * PHPUnit sets OAUTH_* via phpunit.xml; config/bootstrap.php Dotenv can overwrite them.
 * Restore after container integration tests so JWT suites keep dev-keys under /tmp.
 */
final class OAuthTestEnvironment
{
    public const PUBLIC_KEY = 'file:///tmp/public.key';

    public const PRIVATE_KEY = 'file:///tmp/private.key';

    public static function restorePhpUnitOAuthKeys(): void
    {
        $_ENV['OAUTH_PUBLIC_KEY'] = self::PUBLIC_KEY;
        $_ENV['OAUTH_PRIVATE_KEY'] = self::PRIVATE_KEY;
        putenv('OAUTH_PUBLIC_KEY=' . self::PUBLIC_KEY);
        putenv('OAUTH_PRIVATE_KEY=' . self::PRIVATE_KEY);
    }
}
