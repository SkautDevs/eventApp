<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\CoreStylesheet;

/** Místa: the map screen, with Google's embed, the handbook's plan, or both. */
final class MapTest extends AppTestCase
{
    /**
     * The screen is Místa everywhere a reader meets its name: the tab, the app bar, the
     * document title and the section's label. Only the inner pill keeps Mapa / Plán.
     */
    public function testTheTabIsMista(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/mapa')->getBody();

        self::assertStringContainsString('<span>Místa</span>', $html);
        self::assertStringNotContainsString('<span>Mapa</span>', $html);
        self::assertStringContainsString('<span class="appbar-title">Místa</span>', $html);
        self::assertStringContainsString('<title>Místa · Obrok 2019</title>', $html);
        self::assertStringContainsString('aria-label="Místa"', $html);
        self::assertStringContainsString('data-title="Místa"', $html);
        self::assertStringContainsString('<h1 class="sr-only">Místa</h1>', $html);
        // the inner view switcher is about the kind of map, not the screen
        self::assertStringContainsString('<span class="tabs-label">Mapa</span>', $html);
    }

    public function testADisabledMapIs404(): void
    {
        self::assertSame(404, $this->request($this->createApp('minimal', fixtureEvent: true), 'GET', '/mapa')->getStatusCode());
        self::assertSame(200, $this->request($this->createApp(), 'GET', '/mapa')->getStatusCode());
    }

    /** obrok27 has the embed alone, so its screen is exactly what it was before the plan. */
    public function testMapPage(): void
    {
        $response = $this->request($this->createApp('map-embed', fixtureEvent: true), 'GET', '/mapa');

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
        // the offline note stays the map's next sibling: www/js/map/offline.js finds it that way
        self::assertMatchesRegularExpression('#</div>\s*<p class="empty map-offline map-offline-plan" data-map-offline hidden>#', $html);
        self::assertMatchesRegularExpression('#<div class="map" data-map>\s*<iframe [^>]*></iframe>\s*</div>\s*<p class="empty map-offline#', $html);
        // the button escapes .empty's fade: only the sentence is faded, the note is opaque
        self::assertStringContainsString('<span class="map-offline-text">Mapa potřebuje připojení.</span> <button', $html);
        $css = CoreStylesheet::read();
        self::assertMatchesRegularExpression('/\.empty\.map-offline-plan \{\s*opacity: 1;/', $css);
        self::assertMatchesRegularExpression('/\.map-offline-text \{\s*opacity: 0\.8;/', $css);
    }

    public function testAnEmbedAloneRendersAsBefore(): void
    {
        $html = (string) $this->request($this->createApp('map-embed', fixtureEvent: true), 'GET', '/mapa')->getBody();

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
