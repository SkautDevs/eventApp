<?php

declare(strict_types=1);

namespace Tests\Functional;

final class ThemingTest extends AppTestCase
{
    public function testPaletteIsInjectedFromEventConfig(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/')->getBody();

        self::assertStringContainsString('--color-base: #2a9272', $html);
        self::assertStringContainsString('--color-primary: #96201f', $html);
    }

    public function testFaviconsPointToEventDir(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/')->getBody();

        self::assertStringContainsString('events/obrok19/favicon-32x32.png', $html);
        self::assertStringContainsString('events/obrok19/site.webmanifest', $html);
    }

    public function testCoreStylesheetHasNoColorLiterals(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        // odstranit :root blok nesmí být potřeba — paleta se injektuje z layoutu
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,6}\b|rgba?\(|\b(?:white|black)\b/i', $css);
    }
}
