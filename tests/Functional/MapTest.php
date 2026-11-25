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
        // the offline note's control stays a button: only a script ever shows the note
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

    /**
     * Mapa and Plán have an address each, /mapa and /mapa/plan, and each paints its own
     * view first: the root's data-view and the selected pill. Each pill is a link to its
     * view's address, so a reader without JavaScript switches views too; the shown one is
     * the current page. No tabindex: every pill is reachable without a script, which puts
     * the roving one on at wiring.
     */
    public function testThePlanAddressPaintsThePlan(): void
    {
        $app = $this->createApp('map-plan', fixtureEvent: true);

        $google = (string) $this->request($app, 'GET', '/mapa')->getBody();
        self::assertStringContainsString('data-view="google"', $google);
        self::assertPills('google', $google);

        $response = $this->request($app, 'GET', '/mapa/plan');
        self::assertSame(200, $response->getStatusCode());
        $plan = (string) $response->getBody();
        self::assertStringContainsString('data-view="plan"', $plan);
        self::assertStringNotContainsString('data-view="google"', $plan);
        self::assertPills('plan', $plan);
    }

    /** An event with one pane shows it on either address: the other view does not exist there. */
    public function testEitherAddressShowsTheOnlyPane(): void
    {
        $planOnly = $this->createApp('map-plan-only', fixtureEvent: true);
        foreach (['/mapa/plan', '/mapa'] as $path) {
            $response = $this->request($planOnly, 'GET', $path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertStringContainsString('data-view="plan"', (string) $response->getBody(), $path);
        }

        $response = $this->request($this->createApp('map-embed', fixtureEvent: true), 'GET', '/mapa/plan');
        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('#<div class="map" data-map>\s*<iframe #', (string) $response->getBody());
    }

    /** Both pills of a two-view Místa painted on $view: links to their addresses, the shown one selected and current. */
    private static function assertPills(string $view, string $html): void
    {
        $hrefs = ['google' => '/map-plan/mapa', 'plan' => '/map-plan/mapa/plan'];
        foreach ($hrefs as $pill => $href) {
            $shown = $pill === $view;
            self::assertStringContainsString(
                '<a class="tabs-tab" role="tab" id="map-tab-' . $pill . '" href="' . $href . '" aria-controls="map-view-' . $pill . '"'
                . ' aria-selected="' . ($shown ? 'true' : 'false') . '" data-map-view="' . $pill . '"'
                . ($shown ? ' aria-current="page"' : '') . ' data-morph-keep="aria-selected aria-current">',
                $html,
                $view . ': the ' . $pill . ' pill',
            );
        }
        self::assertSame(1, substr_count($html, 'aria-current="page" data-morph-keep="aria-selected aria-current"'), $view . ': one current pill');
        self::assertDoesNotMatchRegularExpression('/id="map-tab-[a-z]+"[^>]*tabindex/', $html, $view . ': no tabindex in the markup');
    }
}
