<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Assertions for redirect-with-error HTML produced by ScriptAlertResponse.
 */
final class ScriptAlertResponseAssert
{
    public static function assertRedirectWithError(string $html, string $message, string $redirectPath): void
    {
        $url = self::extractRedirectUrl($html);
        Assert::assertStringContainsString($redirectPath, $url);
        Assert::assertStringContainsString('error=' . urlencode($message), $url);
    }

    public static function assertRedirectTo(string $html, string $expectedUrl): void
    {
        Assert::assertSame($expectedUrl, self::extractRedirectUrl($html));
    }

    private static function extractRedirectUrl(string $html): string
    {
        Assert::assertMatchesRegularExpression(
            '/window\.location\.href = (.+);<\/script>/',
            $html,
            'Expected ScriptAlertResponse redirect HTML.'
        );

        preg_match('/window\.location\.href = (.+);<\/script>/', $html, $matches);
        $url = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsString($url);

        return $url;
    }
}
