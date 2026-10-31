<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Templates;

use PHPUnit\Framework\TestCase;

final class BaseLayoutAssetsTest extends TestCase
{
    public function testBaseLayoutUsesSelfHostedStylesheetOnly(): void
    {
        $path = dirname(__DIR__, 2) . '/templates/base.twig';
        $html = (string) file_get_contents($path);

        $this->assertStringContainsString('href="/assets/idp.css"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '#https?://#',
            $html,
            'base layout should not load third-party styles or scripts',
        );
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $html);
    }

    public function testBuiltStylesheetExists(): void
    {
        $cssPath = dirname(__DIR__, 2) . '/public/assets/idp.css';
        $this->assertFileExists($cssPath);
        $css = (string) file_get_contents($cssPath);
        $this->assertStringContainsString('data:font/woff2;base64,', $css);
        $this->assertDoesNotMatchRegularExpression('#url\\(/assets/(fonts|webfonts)/#', $css);
    }
}
