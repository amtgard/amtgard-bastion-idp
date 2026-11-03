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

    public function testBuiltStylesheetCoversWeightsAndItalicsUsedInTemplates(): void
    {
        $cssPath = dirname(__DIR__, 2) . '/public/assets/idp.css';
        $css = (string) file_get_contents($cssPath);
        // PR94 shipped only Roboto Condensed 400/700 normal, dropping the
        // 500/600 weights (font-medium/font-semibold) and all Roboto italics
        // the old Google Fonts link provided. Every face below must be inlined.
        $this->assertStringContainsString('font-weight:500', $css);
        $this->assertStringContainsString('font-weight:600', $css);
        $this->assertStringContainsString('font-style:italic', $css);
        $this->assertStringContainsString('Roboto Condensed', $css);
        $this->assertStringContainsString('Instrument Serif', $css);
    }

    public function testBuiltStylesheetFontFacesHaveSingleWoff2Format(): void
    {
        $cssPath = dirname(__DIR__, 2) . '/public/assets/idp.css';
        $css = (string) file_get_contents($cssPath);

        // The copy-frontend-fonts step used to strip only the woff/ttf url(),
        // leaving an orphan format("woff") / format("truetype") behind the
        // woff2 url. Two format() hints for one url is invalid CSS, so
        // browsers dropped every @font-face rule and neither the text fonts
        // nor the Font Awesome icons loaded at all.
        $this->assertStringNotContainsString('format("woff2") format(', $css);
        $this->assertStringNotContainsString('format("woff")', $css);
        $this->assertStringNotContainsString('format("truetype")', $css);

        $matches = [];
        $count = preg_match_all('#@font-face\{[^}]*\}#', $css, $matches);
        $this->assertSame(11, $count, 'expected 11 inlined @font-face rules');
        foreach ($matches[0] as $face) {
            $this->assertMatchesRegularExpression(
                '#src:url\(data:font/woff2;base64,[A-Za-z0-9+/=]+\) format\("woff2"\)\}#',
                $face,
                'each @font-face must have exactly one woff2 data URI and one format hint',
            );
        }
    }
}
