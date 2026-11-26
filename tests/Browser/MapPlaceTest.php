<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * A place opened with JavaScript: the pin in a programme's sheet (obrok27's programme 11, at
 * Plaza) stays in the app, Místa shows the place's marker on the plan zoomed to 250 % and
 * centred, and Google's iframe is centred on it (www/js/map/place.js). A Místa address
 * without `misto` shows no marker and the plain iframe again. The lane is hermetic, so
 * Google's iframe never loads; only its `src` is read.
 */
#[Group('browser')]
final class MapPlaceTest extends BrowserTestCase
{
    private const string MAP = '[data-screen="/obrok27/mapa"]';

    private const string ROOT = 'document.querySelector(\'[data-screen="/obrok27/mapa"] [data-map-root]\')';

    private const string PROGRAM = '[data-screen="/obrok27/programy"]';

    /** The Program screen on show on Přehled, wired. */
    private const string OVERVIEW = 'const s = document.querySelector(\'[data-screen="/obrok27/programy"]\'); const root = s && s.querySelector(\'[data-pg-root][data-pg-ready="1"]\'); return !!root && !s.hidden && root.dataset.view === "overview";';

    /** Místa on show at the plan's address of the place arguments[0], its marker shown and every other one hidden. */
    private const string ON_THE_PLACE = <<<'JS'
        const s = document.querySelector('[data-screen="/obrok27/mapa"]');
        if (!s || s.hidden || location.pathname !== '/obrok27/mapa/plan' || location.search !== '?misto=' + arguments[0]) {
            return false;
        }
        const markers = Array.from(s.querySelectorAll('[data-plan-canvas] [data-place]'));
        return markers.length > 1 && markers.every(marker => marker.hidden === (marker.dataset.place !== arguments[0]));
        JS;

    /** The plan's box and canvas, the marker's centre against the box's centre, and the box's scroll and its end. */
    private const string GEOMETRY = <<<'JS'
        const s = document.querySelector('[data-screen="/obrok27/mapa"]');
        const box = s.querySelector('[data-plan]');
        const canvas = s.querySelector('[data-plan-canvas]');
        const marker = s.querySelector('#misto-' + arguments[0]);
        const b = box.getBoundingClientRect();
        const m = marker.getBoundingClientRect();
        return {
            boxWidth: box.clientWidth,
            boxHeight: box.clientHeight,
            canvasWidth: canvas.getBoundingClientRect().width,
            dx: m.left + m.width / 2 - (b.left + box.clientWidth / 2),
            dy: m.top + m.height / 2 - (b.top + box.clientHeight / 2),
            left: box.scrollLeft,
            top: box.scrollTop,
            maxLeft: box.scrollWidth - box.clientWidth,
            maxTop: box.scrollHeight - box.clientHeight,
        };
        JS;

    /** Opens programme 11's sheet on Přehled and returns the place its pin names. */
    private static function openPlazaSheet(): string
    {
        self::waitFor(self::OVERVIEW);
        self::script('document.querySelector(\'' . self::PROGRAM . ' #pg-view-overview .pl-open[data-pg-open="11"]\').click();');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="11"] .place-pin\') !== null;');
        $href = (string) self::script('return document.querySelector(".sheet-body.is-open .place-pin").getAttribute("href");');
        self::assertSame(1, preg_match('#^/obrok27/mapa/plan\?misto=([1-9]\d*)\#misto-\1$#', $href, $match), 'the pin leads to the plan: ' . $href);

        return $match[1];
    }

    /** Taps the pin of the open sheet and waits for Místa on the place's plan. */
    private static function tapPin(string $place): void
    {
        self::tap('.sheet-body.is-open .place-pin');
        self::waitFor(self::ON_THE_PLACE, [$place]);
    }

    /** Waits until the plan is at 250 % of its box and the marker is centred, or the box is scrolled to its end. */
    private static function assertCentred(string $place): void
    {
        self::waitFor(<<<'JS'
            const s = document.querySelector('[data-screen="/obrok27/mapa"]');
            const box = s.querySelector('[data-plan]');
            const canvas = s.querySelector('[data-plan-canvas]');
            return canvas.getBoundingClientRect().height > 0 && Math.abs(canvas.getBoundingClientRect().width / box.clientWidth - 2.5) < 0.05 && (box.scrollTop > 0 || box.scrollLeft > 0);
            JS);
        $at = self::script(self::GEOMETRY, [$place]);
        self::assertIsArray($at);
        self::assertEqualsWithDelta(2.5 * $at['boxWidth'], $at['canvasWidth'], 0.02 * 2.5 * $at['boxWidth'], 'the canvas is zoomed to 250 %');
        self::assertTrue(abs($at['dx']) <= 0.15 * $at['boxWidth'] || $at['left'] <= 0 || $at['left'] >= $at['maxLeft'] - 1, 'centred across: ' . json_encode($at));
        self::assertTrue(abs($at['dy']) <= 0.15 * $at['boxHeight'] || $at['top'] <= 0 || $at['top'] >= $at['maxTop'] - 1, 'centred down: ' . json_encode($at));
    }

    /** The place's coordinates from Místa's places list: [lat, lng]. @return array{string, string} */
    private static function coordinates(string $place): array
    {
        $at = self::script('const el = document.querySelector(\'' . self::MAP . ' [data-map-places] [data-place="\' + arguments[0] + \'"]\'); return el ? [el.dataset.lat, el.dataset.lng] : null;', [$place]);
        self::assertIsArray($at, 'place ' . $place . ' is in the places list');

        return [(string) $at[0], (string) $at[1]];
    }

    private static function iframeSrc(): string
    {
        return (string) self::script('return document.querySelector(\'' . self::MAP . ' iframe[data-embed]\').getAttribute("src");');
    }

    public function testThePinOpensThePlanOnTheMarkerAndBackReopensTheSheet(): void
    {
        self::visit('/obrok27/programy/prehled');
        $place = self::openPlazaSheet();
        $sheet = (string) self::script('return location.hash;');
        self::assertMatchesRegularExpression('/^#section-\d+-program-11$/', $sheet);
        self::script('window.__mark = 1;');

        self::tapPin($place);

        self::assertSame(1, self::script('return window.__mark;'), 'the loader took the pin: no document load');
        self::assertSame('#misto-' . $place, self::script('return location.hash;'));
        self::assertCentred($place);

        self::$browser->back();
        self::waitFor('return location.pathname === "/obrok27/programy/prehled" && location.hash === arguments[0]'
            . ' && document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="11"]\') !== null;', [$sheet]);
    }

    /** A pill keeps the place: Mapa is the map's address with the same query, centred on it, one entry. */
    public function testThePillKeepsThePlaceOnMapa(): void
    {
        self::visit('/obrok27/programy/prehled');
        $place = self::openPlazaSheet();
        self::tapPin($place);
        [$lat, $lng] = self::coordinates($place);
        $length = self::script('return history.length;');

        self::tap('#map-tab-google');
        self::waitFor('return location.pathname === "/obrok27/mapa" && ' . self::ROOT . '.dataset.view === "google";');
        self::assertSame('?misto=' . $place, self::script('return location.search;'));
        self::assertStringContainsString('&ll=' . $lat . ',' . $lng . '&z=18', self::iframeSrc());
        self::assertSame($length + 1, self::script('return history.length;'), 'one entry for the view');

        // the view already on show pushes nothing, query or not
        self::tap('#map-tab-google');
        self::assertSame($length + 1, self::script('return history.length;'));
    }

    /**
     * Review Focus 5: Místa already in the stack was rendered for its bare address, every
     * marker hidden; the pin shows and centres the place, and a later tab tap to Místa, an
     * address without the query, shows no marker.
     */
    public function testAStackedMistaShowsThePlaceAndATabTapHidesIt(): void
    {
        self::visit('/obrok27/mapa');
        self::waitFor('const root = ' . self::ROOT . '; return root !== null && root.dataset.mapReady === "1";');
        self::tap('.tabbar a[href="/obrok27/programy"]');
        self::waitFor('const s = document.querySelector(\'' . self::PROGRAM . '\'); return !!s && !s.hidden && s.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::tap(self::PROGRAM . ' [data-pg-view="overview"]');
        $place = self::openPlazaSheet();

        self::tapPin($place);
        self::assertCentred($place);

        self::tap('.tabbar a[href="/obrok27/informace"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/obrok27/informace"]\'); return !!s && !s.hidden;');
        self::tap('.tabbar a[href="/obrok27/mapa"]');
        self::waitFor('const s = document.querySelector(\'' . self::MAP . '\'); return !!s && !s.hidden && location.pathname === "/obrok27/mapa/plan";');
        self::assertSame('', self::script('return location.search;'));
        self::waitFor('return Array.from(document.querySelectorAll(\'' . self::MAP . ' [data-place]\')).every(el => el.hidden || el.closest("[data-map-places]") !== null);');
        self::assertSame(0, self::script('return Array.from(document.querySelectorAll(\'' . self::MAP . ' [data-plan-canvas] [data-place]\')).filter(el => !el.hidden).length;'));
    }

    /**
     * Review Focus 2, the marker's half: the pin's visit revalidates Místa for its query, and
     * once that answer has landed the marker is still shown and the iframe still centred. The
     * morph never writes an iframe, so the centred `src` is place.js's.
     */
    public function testAMorphKeepsTheShownMarker(): void
    {
        self::visit('/obrok27/mapa');
        self::waitFor('const root = ' . self::ROOT . '; return root !== null && root.dataset.mapReady === "1";');
        $before = self::script('return document.querySelector(\'' . self::MAP . '\').getAttribute("data-fetched-at");');
        self::assertFalse(self::script('return document.querySelector("#map-tab-google").getAttribute("href").includes("misto=");'));
        self::tap('.tabbar a[href="/obrok27/programy"]');
        self::waitFor('const s = document.querySelector(\'' . self::PROGRAM . '\'); return !!s && !s.hidden && s.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::tap(self::PROGRAM . ' [data-pg-view="overview"]');
        $place = self::openPlazaSheet();
        // data-fetched-at counts seconds: the revalidation's answer is then a later one
        usleep(1_100_000);
        self::script('window.__mark = 1;');

        self::tapPin($place);
        self::assertSame(1, self::script('return window.__mark;'), 'the stacked Místa was shown: no document load');
        [$lat, $lng] = self::coordinates($place);
        // the answer for ?misto=<p> has landed: a newer time, and the pills name the place
        self::waitFor('const s = document.querySelector(\'' . self::MAP . '\'); return s.getAttribute("data-fetched-at") !== arguments[0]'
            . ' && document.querySelector("#map-tab-google").getAttribute("href").includes("misto=" + arguments[1]);', [$before, $place]);

        self::assertFalse(self::script('return document.querySelector(\'' . self::MAP . ' #misto-\' + arguments[0]).hidden;', [$place]));
        self::assertStringContainsString('&ll=' . $lat . ',' . $lng . '&z=18', self::iframeSrc());
    }

    /**
     * Back and Forward through a place: leaving Místa leaves its iframe alone, Forward shows
     * the place again, and a Místa address without `misto` hides the marker and puts the
     * iframe back on its plain embed.
     */
    public function testAMistaAddressWithoutMistoHidesTheMarkerAndRestoresTheIframe(): void
    {
        self::visit('/obrok27/programy/prehled');
        $place = self::openPlazaSheet();
        self::tapPin($place);
        self::tap('#map-tab-google');
        self::waitFor('return location.pathname === "/obrok27/mapa" && ' . self::ROOT . '.dataset.view === "google";');
        $centred = self::iframeSrc();
        self::assertStringContainsString('&z=18', $centred);

        self::$browser->back();
        self::waitFor('return location.pathname === "/obrok27/mapa/plan" && ' . self::ROOT . '.dataset.view === "plan";');
        // With motion the loader swaps the screens inside a view transition, after the
        // popstate listeners have run, so Místa is still on show when its own sees the
        // Program address; the lane forces reduced motion, which swaps at once.
        self::devTools()->execute('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']]]);
        try {
            self::assertFalse(self::script('return matchMedia("(prefers-reduced-motion: reduce)").matches;'));
            self::$browser->back();
            self::waitFor('const s = document.querySelector(\'' . self::PROGRAM . '\'); return !!s && !s.hidden && location.pathname === "/obrok27/programy/prehled";');
        } finally {
            self::devTools()->execute('Emulation.setEmulatedMedia', ['features' => []]);
        }
        self::assertTrue(self::script('return matchMedia("(prefers-reduced-motion: reduce)").matches;'), 'the lane\'s reduced motion is back');
        self::assertSame($centred, self::iframeSrc(), 'leaving Místa leaves its iframe alone');

        self::$browser->forward();
        self::waitFor(self::ON_THE_PLACE, [$place]);

        self::script('history.pushState({screen: "/obrok27/mapa"}, "", "/obrok27/mapa"); window.dispatchEvent(new PopStateEvent("popstate", {state: history.state}));');
        self::waitFor('return ' . self::ROOT . '.dataset.view === "google";');
        self::assertSame(0, self::script('return Array.from(document.querySelectorAll(\'' . self::MAP . ' [data-plan-canvas] [data-place]\')).filter(el => !el.hidden).length;'));
        self::assertSame(
            self::script('return document.querySelector(\'' . self::MAP . ' iframe[data-embed]\').dataset.embed;'),
            self::iframeSrc(),
        );
    }

    /** The plan's canvas width as a multiple of its box, and the stored scale (null when none). */
    private const string SCALE = <<<'JS'
        const s = document.querySelector('[data-screen="/obrok27/mapa"]');
        const box = s.querySelector('[data-plan]');
        const canvas = s.querySelector('[data-plan-canvas]');
        return {scale: canvas.getBoundingClientRect().width / box.clientWidth, stored: sessionStorage.getItem('planZoom:obrok27')};
        JS;

    /** Back from the place to Přehled's reopened sheet, closed, then the tab to Místa: its plan on show, no place on the address. */
    private static function backAndTabToMista(): void
    {
        self::$browser->back();
        self::waitFor(self::OVERVIEW);
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="11"]\') !== null;');
        self::tap('.sheet.is-open .sheet-close');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
        self::tap('.tabbar a[href="/obrok27/mapa"]');
        self::waitFor('const s = document.querySelector(\'' . self::MAP . '\'); return !!s && !s.hidden && location.pathname === "/obrok27/mapa/plan" && ' . self::ROOT . '.dataset.view === "plan";');
        self::assertSame('', self::script('return location.search;'));
    }

    /**
     * Final review F1: the place's 250 % lasts as long as the place. Back and a tab tap to a
     * bare Místa show the plan at its box's width again, and nothing was stored.
     */
    public function testThePlaceZoomDoesNotOutliveThePlace(): void
    {
        self::visit('/obrok27/programy/prehled');
        $place = self::openPlazaSheet();
        self::tapPin($place);
        self::assertCentred($place);

        self::backAndTabToMista();

        $at = self::script(self::SCALE);
        self::assertIsArray($at);
        self::assertEqualsWithDelta(1.0, $at['scale'], 0.02, 'the plan is at its box\'s width again');
        self::assertNull($at['stored'], 'the place zoom stores nothing');
    }

    /** Final review F1: a scale the reader chose before the pin comes back after the place. */
    public function testTheReadersStoredScaleComesBackAfterThePlace(): void
    {
        self::visit('/obrok27/mapa/plan');
        self::waitFor('const root = ' . self::ROOT . '; return root !== null && root.dataset.mapReady === "1";');
        self::tap(self::MAP . ' [data-plan-zoom="in"]');
        self::assertSame('1.4', self::script('return sessionStorage.getItem("planZoom:obrok27");'));
        self::tap('.tabbar a[href="/obrok27/programy"]');
        self::waitFor('const s = document.querySelector(\'' . self::PROGRAM . '\'); return !!s && !s.hidden && s.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::tap(self::PROGRAM . ' [data-pg-view="overview"]');
        $place = self::openPlazaSheet();
        self::tapPin($place);
        self::assertCentred($place);

        self::backAndTabToMista();

        $at = self::script(self::SCALE);
        self::assertIsArray($at);
        self::assertEqualsWithDelta(1.4, $at['scale'], 0.03, 'the reader\'s own scale');
        self::assertSame('1.4', $at['stored']);
    }

    /**
     * From Task 7: the zoom widens the canvas, not the image alone, so a marker positioned in
     * % of the canvas stays over the same point of the drawing at every scale.
     */
    public function testAMarkerStaysOnItsPointOfTheDrawingWhenZoomed(): void
    {
        self::visit('/obrok27/programy/prehled');
        $place = self::openPlazaSheet();
        self::tapPin($place);
        self::assertCentred($place);

        $share = <<<'JS'
            const s = document.querySelector('[data-screen="/obrok27/mapa"]');
            const img = s.querySelector('[data-plan-img]').getBoundingClientRect();
            const m = s.querySelector('#misto-' + arguments[0]).getBoundingClientRect();
            return [(m.left + m.width / 2 - img.left) / img.width, (m.top + m.height / 2 - img.top) / img.height];
            JS;
        $at = self::script($share, [$place]);
        self::assertIsArray($at);
        self::tap(self::MAP . ' [data-plan-zoom="in"]');
        $zoomed = self::script($share, [$place]);
        self::assertIsArray($zoomed);
        self::assertEqualsWithDelta($at[0], $zoomed[0], 0.005, 'across');
        self::assertEqualsWithDelta($at[1], $zoomed[1], 0.005, 'down');
        self::tap(self::MAP . ' [data-plan-zoom="out"]');
        self::tap(self::MAP . ' [data-plan-zoom="out"]');
        $out = self::script($share, [$place]);
        self::assertIsArray($out);
        self::assertEqualsWithDelta($at[0], $out[0], 0.005, 'across, zoomed out');
        self::assertEqualsWithDelta($at[1], $out[1], 0.005, 'down, zoomed out');
    }
}
