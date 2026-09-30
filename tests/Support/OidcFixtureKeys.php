<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

final class OidcFixtureKeys
{
    public static function directory(): string
    {
        return dirname(__DIR__) . '/fixtures/oidc';
    }

    public static function privatePem(): string
    {
        return self::read('private.pem');
    }

    public static function publicPem(): string
    {
        return self::read('public.pem');
    }

    public static function privateKeyPath(): string
    {
        return self::directory() . '/private.pem';
    }

    public static function publicKeyPath(): string
    {
        return self::directory() . '/public.pem';
    }

    private static function read(string $filename): string
    {
        $contents = file_get_contents(self::directory() . '/' . $filename);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read OIDC fixture ' . $filename);
        }

        return $contents;
    }
}
