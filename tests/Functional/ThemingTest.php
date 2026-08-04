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
        // dropping the :root block must never be necessary — the palette is injected by the layout.
        // (?!-) keeps the property name "white-space" from reading as the colour keyword.
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,6}\b|rgba?\(|\b(?:white|black)\b(?!-)/i', $css);
    }

    public function testPageBackgroundComesFromPalette(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        self::assertStringContainsString('var(--color-background)', $css);
    }

    public function testLinksAreThemedNotBrowserDefaultBlue(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        self::assertStringContainsString('var(--color-link)', $css);
    }

    public function testAppBarCarriesThePageTitle(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/novinky')->getBody();

        self::assertStringContainsString('<span class="appbar-title">Novinky</span>', $html);
        self::assertStringContainsString('Novinky · Obrok 2019', $html);
    }

    /** The bar is built from the event's features, not from a hardcoded list of five. */
    public function testTabBarOnlyShowsEnabledFeatures(): void
    {
        $html = (string) $this->request($this->createApp('minimal', fixtureEvent: true), 'GET', '/novinky')->getBody();

        self::assertStringContainsString('Novinky', $html);
        self::assertStringNotContainsString('Odkazy', $html);
        self::assertStringNotContainsString('Mapa', $html);
    }
}
