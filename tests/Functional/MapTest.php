<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Program\LocalProgramProvider;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\ProgramUnavailableException;
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

    /**
     * The embed alone keeps its markup inside a root of its own (Plan call 14): no pill, no
     * plan, the iframe a lone child of div.map, which knows its plain address.
     */
    public function testAnEmbedAloneRendersAsBefore(): void
    {
        $html = (string) $this->request($this->createApp('map-embed', fixtureEvent: true), 'GET', '/mapa')->getBody();

        self::assertStringNotContainsString('Zobrazit plán', $html);
        self::assertStringNotContainsString('role="tablist"', $html);
        self::assertStringNotContainsString('data-plan', $html);
        self::assertMatchesRegularExpression('#<div class="map" data-map>\s*<iframe src="https://www\.google\.com/maps/d/u/1/embed\?mid=test" data-embed="https://www\.google\.com/maps/d/u/1/embed\?mid=test" [^>]*></iframe>\s*</div>#', $html);
    }

    public function testTheEmbedAloneIsARoot(): void
    {
        $html = (string) $this->request($this->createApp('map-embed', fixtureEvent: true), 'GET', '/mapa')->getBody();

        self::assertMatchesRegularExpression('#<div data-map-root data-map-event="map-embed" data-view="google">\s*<div class="map" data-map>#', $html);
        // the offline note stays the map's next sibling, inside the root
        self::assertMatchesRegularExpression('#</iframe>\s*</div>\s*<p class="empty map-offline" data-map-offline hidden>Mapa potřebuje připojení\.</p>\s*</div>#', $html);
    }

    /**
     * Every place with GPS inside planBounds gets a marker on the plan, at its share of the
     * image's width and height, hidden until an address names it; a place without GPS or
     * outside the bounds gets none. The image and the markers share one canvas.
     */
    public function testEveryPlaceOnThePlanHasAHiddenMarker(): void
    {
        $html = $this->mapPlaces('/mapa/plan');

        self::assertStringContainsString('<span class="plan-marker" id="misto-1" data-key="place-1" data-place="1" style="left: 25.00%; top: 50.00%" hidden data-morph-keep="hidden"><span class="plan-marker-label">Brána</span></span>', $html);
        self::assertStringContainsString('<span class="plan-marker" id="misto-4" data-key="place-4" data-place="4" style="left: 0.00%; top: 0.00%" hidden data-morph-keep="hidden"><span class="plan-marker-label">Roh</span></span>', $html);
        self::assertStringNotContainsString('misto-2', $html);
        self::assertStringNotContainsString('misto-3', $html);
        self::assertLessThan(strpos($html, 'id="misto-4"'), strpos($html, 'id="misto-1"'), 'markers in the places order');
        self::assertMatchesRegularExpression('#<div class="plan" data-plan>\s*<div class="plan-canvas" data-plan-canvas>\s*<img class="plan-img" src="/events/obrok19/plan-example\.png" alt="Plán areálu" data-plan-img>\s*<span class="plan-marker" id="misto-1"#', $html);
    }

    public function testMistoShowsItsMarkerAndCentresTheEmbed(): void
    {
        $html = $this->mapPlaces('/mapa/plan?misto=1');

        self::assertStringContainsString('<span class="plan-marker" id="misto-1" data-key="place-1" data-place="1" style="left: 25.00%; top: 50.00%" data-morph-keep="hidden">', $html);
        self::assertStringContainsString('id="misto-4" data-key="place-4" data-place="4" style="left: 0.00%; top: 0.00%" hidden data-morph-keep="hidden">', $html);
        self::assertStringContainsString('<iframe src="https://www.google.com/maps/d/embed?mid=test&amp;ll=50.005000,15.005000&amp;z=18" data-embed="https://www.google.com/maps/d/embed?mid=test"', $html);
        self::assertStringContainsString('id="map-tab-plan" href="/map-places/mapa/plan?misto=1#misto-1"', $html);
        self::assertStringContainsString('id="map-tab-google" href="/map-places/mapa?misto=1"', $html);
        self::assertStringContainsString('data-view="plan"', $html);
    }

    public function testAPlaceOutsideThePlanStillCentresTheEmbed(): void
    {
        $html = $this->mapPlaces('/mapa?misto=3');

        self::assertSame(substr_count($html, 'class="plan-marker"'), substr_count($html, ' hidden data-morph-keep="hidden"'), 'no marker is shown');
        self::assertStringContainsString('<iframe src="https://www.google.com/maps/d/embed?mid=test&amp;ll=51.000000,15.000000&amp;z=18"', $html);
        self::assertStringContainsString('id="map-tab-plan" href="/map-places/mapa/plan?misto=3#misto-3"', $html);
    }

    /** The embed carries every place with GPS, on the plan or off it, for a script to centre on. */
    public function testTheEmbedKnowsEveryPlaceWithGps(): void
    {
        $html = $this->mapPlaces('/mapa');

        self::assertMatchesRegularExpression(
            '#<iframe [^>]*></iframe>\s*<div data-map-places hidden>'
            . '<span data-key="place-1" data-place="1" data-lat="50.005000" data-lng="15.005000"></span>'
            . '<span data-key="place-3" data-place="3" data-lat="51.000000" data-lng="15.000000"></span>'
            . '<span data-key="place-4" data-place="4" data-lat="50.010000" data-lng="15.000000"></span>'
            . '</div>\s*</div>\s*<p class="empty map-offline#',
            $html,
        );
        self::assertStringNotContainsString('data-place="2"', $html);
    }

    /** A plan-only event has no embed, so no list for one. */
    public function testAPlanOnlyEventHasMarkersAndNoPlacesList(): void
    {
        $html = (string) $this->request($this->createApp('map-places-plan', fixtureEvent: true), 'GET', '/mapa?misto=1')->getBody();

        self::assertStringContainsString('<span class="plan-marker" id="misto-1" data-key="place-1" data-place="1" style="left: 25.00%; top: 50.00%" data-morph-keep="hidden">', $html);
        self::assertStringNotContainsString('data-map-places', $html);
        self::assertStringNotContainsString('<iframe', $html);
    }

    /** @return array<string, array{string}> */
    public static function ignoredMisto(): array
    {
        return [
            'not a number' => ['?misto=abc'],
            'an array' => ['?misto[]=1'],
            'a leading zero' => ['?misto=007'],
            'too long' => ['?misto=99999999999'],
            'a place without GPS' => ['?misto=2'],
            'an unknown place' => ['?misto=42'],
            'zero' => ['?misto=0'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ignoredMisto')]
    public function testAnInvalidOrUnknownMistoIsIgnored(string $query): void
    {
        $response = $this->request($this->createApp('map-places', fixtureEvent: true), 'GET', '/mapa/plan' . $query);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertSame(2, substr_count($html, 'class="plan-marker"'));
        self::assertSame(2, substr_count($html, ' hidden data-morph-keep="hidden"'), 'every marker hidden');
        self::assertStringContainsString('<iframe src="https://www.google.com/maps/d/embed?mid=test" data-embed="https://www.google.com/maps/d/embed?mid=test"', $html);
        self::assertStringContainsString('id="map-tab-plan" href="/map-places/mapa/plan" ', $html);
        self::assertStringContainsString('id="map-tab-google" href="/map-places/mapa" ', $html);
    }

    /** @return array<string, array{\Throwable}> */
    public static function providerFailures(): array
    {
        return [
            'unavailable' => [new ProgramUnavailableException('down')],
            'bad data' => [new ProgramDataException('garbled')],
        ];
    }

    /** The places are programme data: without them Místa still renders, with no marker. */
    #[\PHPUnit\Framework\Attributes\DataProvider('providerFailures')]
    public function testAProviderFailureLeavesTheMapWithoutMarkers(\Throwable $failure): void
    {
        $app = $this->createApp('map-places', [ProgramProviderInterface::class => new ThrowingProgramProvider(programsException: $failure)], fixtureEvent: true);
        $response = $this->request($app, 'GET', '/mapa/plan?misto=1');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('data-plan-img', $html);
        self::assertStringNotContainsString('plan-marker"', $html);
        self::assertStringNotContainsString('data-map-places', $html);
        self::assertStringContainsString('<iframe src="https://www.google.com/maps/d/embed?mid=test" ', $html);
    }

    /** Only Program links to a place (Plan call 11): an event without it asks the provider nothing. */
    public function testAnEventWithoutProgramsReadsNoPlaces(): void
    {
        $provider = new CountingProgramProvider(new LocalProgramProvider(dirname(__DIR__) . '/fixtures/events/map-places/fixtures', $this->stubState));
        $app = $this->createApp('map-plan', [ProgramProviderInterface::class => $provider], fixtureEvent: true);

        self::assertSame(200, $this->request($app, 'GET', '/mapa/plan?misto=1')->getStatusCode());
        self::assertSame(0, $provider->placeReads);
        self::assertSame(0, $provider->programReads);
    }

    private function mapPlaces(string $path): string
    {
        $response = $this->request($this->createApp('map-places', fixtureEvent: true), 'GET', $path);
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
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
