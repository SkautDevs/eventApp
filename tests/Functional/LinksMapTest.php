<?php

declare(strict_types=1);

namespace Tests\Functional;

final class LinksMapTest extends AppTestCase
{
    public function testLinksPage(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/odkazy');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Krizový telefon', (string) $response->getBody());
    }

    public function testLinksAreCardsInAList(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/odkazy')->getBody();

        self::assertStringContainsString('<ul class="link-list">', $html);
        // the handbook first, tonal
        self::assertMatchesRegularExpression('#<ul class="link-list">\s*<li>\s*<a class="link-card is-highlight" href="[^"]*handbook#', $html);
        // an external link carries the outward mark, hidden from the accessibility tree
        self::assertStringContainsString('href="https://obrok.skaut.cz/"', $html);
        self::assertStringContainsString('class="link-card-out fas fa-external-link-alt" aria-hidden="true"', $html);
    }

    /** obrok27 has the embed alone, so its screen is exactly what it was before the plan. */
    public function testMapPage(): void
    {
        $response = $this->request($this->createApp('obrok27'), 'GET', '/mapa');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('google.com/maps/d/u/1/embed', $html);
        self::assertStringContainsString('<div class="map" data-map>', $html);
        self::assertStringContainsString('<p class="empty map-offline" data-map-offline hidden>Mapa potřebuje připojení.</p>', $html);
    }

    public function testBothMapsMakeTwoViewsWithAPill(): void
    {
        $html = (string) $this->request($this->createApp('map-plan', fixtureEvent: true), 'GET', '/mapa')->getBody();

        self::assertStringContainsString('data-map-root', $html);
        self::assertStringContainsString('data-map-event="map-plan"', $html);
        self::assertStringContainsString('data-morph-keep="data-view"', $html);
        self::assertStringContainsString('<iframe src="https://www.google.com/maps/d/embed?mid=test"', $html);
        self::assertMatchesRegularExpression('#<img class="plan-img" src="/?events/obrok19/Obrok19_minilogo\.png" alt="Plán testovacího areálu" data-plan-img>#', $html);
        self::assertStringContainsString('aria-label="Zobrazení mapy"', $html);
        self::assertStringContainsString('data-map-view="plan"', $html);
        self::assertStringContainsString('<button type="button" class="btn" data-map-view="plan">Zobrazit plán</button>', $html);
        // the offline note stays the map's next sibling: www/shell.js finds it that way
        self::assertMatchesRegularExpression('#</div>\s*<p class="empty map-offline map-offline-plan" data-map-offline hidden>#', $html);
        self::assertMatchesRegularExpression('#<div class="map" data-map>\s*<iframe [^>]*></iframe>\s*</div>\s*<p class="empty map-offline#', $html);
        // the button escapes .empty's fade: only the sentence is faded, the note is opaque
        self::assertStringContainsString('<span class="map-offline-text">Mapa potřebuje připojení.</span> <button', $html);
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        self::assertMatchesRegularExpression('/\.empty\.map-offline-plan \{\s*opacity: 1;/', $css);
        self::assertMatchesRegularExpression('/\.map-offline-text \{\s*opacity: 0\.8;/', $css);
    }

    public function testAnEmbedAloneRendersAsBefore(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/mapa')->getBody();

        self::assertStringNotContainsString('data-map-root', $html);
        self::assertStringNotContainsString('Zobrazit plán', $html);
        self::assertStringContainsString('<div class="map" data-map>', $html);
    }

    public function testAPlanAloneHasNoPill(): void
    {
        $html = (string) $this->request($this->createApp('map-plan-only', fixtureEvent: true), 'GET', '/mapa')->getBody();

        self::assertStringContainsString('data-map-root', $html);
        self::assertStringContainsString('data-view="plan"', $html);
        self::assertStringContainsString('alt="Plán areálu"', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('role="tablist"', $html);
    }
}
