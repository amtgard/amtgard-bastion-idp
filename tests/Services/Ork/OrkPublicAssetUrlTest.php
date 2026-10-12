<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Ork;

use Amtgard\IdP\Services\Ork\OrkPublicAssetUrl;
use PHPUnit\Framework\TestCase;

final class OrkPublicAssetUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['ORK_BASE_URL']);
        parent::tearDown();
    }

    public function testRewritesDockerHostToOrkBaseOrigin(): void
    {
        $_ENV['ORK_BASE_URL'] = 'http://localhost:19080/orkui/';

        $url = OrkPublicAssetUrl::forBrowser(
            'http://ork3-php8-app/assets/players/000004.jpg?1770927377',
        );

        $this->assertSame(
            'http://localhost:19080/assets/players/000004.jpg?1770927377',
            $url,
        );
    }

    public function testFixesDuplicateQuerySeparators(): void
    {
        $_ENV['ORK_BASE_URL'] = 'http://localhost:19080/orkui/';

        $url = OrkPublicAssetUrl::forBrowser(
            'http://ork3-php8-app/assets/heraldry/player/000004.jpg?v=1785678925?1770927377',
        );

        $this->assertSame(
            'http://localhost:19080/assets/heraldry/player/000004.jpg?v=1785678925&1770927377',
            $url,
        );
    }

    public function testLeavesAbsoluteExternalUrlsUntouched(): void
    {
        $_ENV['ORK_BASE_URL'] = 'http://localhost:19080/orkui/';

        $url = OrkPublicAssetUrl::forBrowser('https://cdn.example.com/avatar.png');

        $this->assertSame('https://cdn.example.com/avatar.png', $url);
    }
}
