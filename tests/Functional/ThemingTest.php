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
        // dropping the :root block must never be necessary — the palette is injected by the layout
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,6}\b|rgba?\(|\b(?:white|black)\b/i', $css);
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

    /**
     * The stripe is opt-in per event and carries padding/margins, so it must not
     * reach an event that did not ask for it — obrok19 is live and its headings
     * have to keep their original geometry.
     */
    public function testHeadingStripeIsOptIn(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/novinky')->getBody();

        self::assertStringContainsString('class="heading-plain"', $html);
        self::assertStringNotContainsString('heading-stripe', $html);
    }
}
