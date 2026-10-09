<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * Mapa with both views — Google's embed and the handbook plan — on obrok19, whose
 * config carries a sample plan for exactly this. The lane is hermetic, so Google's
 * iframe never loads here; the plan is self-hosted and does.
 */
#[Group('browser')]
final class MapViewsTest extends BrowserTestCase
{
    private const string ROOT = 'document.querySelector("[data-screen=\'/obrok19/mapa\'] [data-map-root]")';

    /** A fresh landing on Mapa with nothing remembered, wired by www/map.js. */
    private static function openMap(): void
    {
        self::visit('/obrok19/mapa');
        self::script('sessionStorage.clear();');
        self::visit('/obrok19/mapa');
        self::waitFor('const root = ' . self::ROOT . '; return root !== null && root.dataset.mapReady === "1";');
    }

    public function testThePillSwitchesToThePlanAndRemembersIt(): void
    {
        self::openMap();
        self::assertSame('google', self::script('return ' . self::ROOT . '.dataset.view;'));

        self::tap('[role="tab"][data-map-view="plan"]');
        self::waitFor('return ' . self::ROOT . '.dataset.view === "plan"'
            . ' && document.querySelector("[data-plan-img]").getBoundingClientRect().width > 0;');
        self::assertSame('true', self::script('return document.querySelector(\'[role="tab"][data-map-view="plan"]\').getAttribute("aria-selected");'));
        // the Google pane is hidden, not gone: its iframe keeps running behind the plan
        self::assertSame(1, self::script('return document.querySelectorAll("[data-map] iframe").length;'));

        self::visit('/obrok19/mapa');
        self::waitFor('const root = ' . self::ROOT . '; return root !== null && root.dataset.mapReady === "1" && root.dataset.view === "plan";');
    }

    public function testZoomWidensThePlanAndStopsAtTheEnds(): void
    {
        self::openMap();
        self::tap('[role="tab"][data-map-view="plan"]');
        self::waitFor('return document.querySelector("[data-plan-img]").getBoundingClientRect().width > 0;');
        $width = 'return document.querySelector("[data-plan-img]").getBoundingClientRect().width;';
        $before = (float) self::script($width);

        // opened at the plan's own width, so there is nothing to zoom out of
        self::assertTrue(self::script('return document.querySelector(\'[data-plan-zoom="out"]\').disabled;'));
        self::tap('[data-plan-zoom="in"]');
        self::assertGreaterThan($before * 1.3, (float) self::script($width));
        self::assertFalse(self::script('return document.querySelector(\'[data-plan-zoom="out"]\').disabled;'));

        self::assertTrue(self::script('const b = document.querySelector(\'[data-plan-zoom="in"]\'); for (let i = 0; i < 10; i++) { b.click(); } return b.disabled;'));
        self::assertTrue(self::script('const b = document.querySelector(\'[data-plan-zoom="out"]\'); for (let i = 0; i < 10; i++) { b.click(); } return b.disabled;'));
        self::assertEqualsWithDelta($before, (float) self::script($width), 1.0);
    }

    /** The zoom is remembered too, and a remembered one opens without a jump. */
    public function testAStoredZoomOpensAtItsWidth(): void
    {
        self::openMap();
        self::tap('[role="tab"][data-map-view="plan"]');
        self::waitFor('return document.querySelector("[data-plan-img]").getBoundingClientRect().width > 0;');
        $box = (float) self::script('return document.querySelector("[data-plan]").clientWidth;');
        self::tap('[data-plan-zoom="in"]');

        self::visit('/obrok19/mapa');
        self::waitFor('const root = ' . self::ROOT . '; return root !== null && root.dataset.view === "plan";');
        self::assertEqualsWithDelta($box * 1.4, (float) self::script('return document.querySelector("[data-plan-img]").getBoundingClientRect().width;'), 2.0);
    }

    public function testTheMapViewSurvivesLeavingTheScreen(): void
    {
        self::openMap();
        self::tap('[role="tab"][data-map-view="plan"]');
        self::waitFor('return ' . self::ROOT . '.dataset.view === "plan";');

        // to another tab through the loader, and back: the screen is shown, not re-rendered
        self::tap('.tabbar a[href="/obrok19/novinky"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/obrok19/novinky"]\'); return s && !s.hidden;');
        self::assertTrue(self::script('return document.querySelector(\'[data-screen="/obrok19/mapa"]\').hidden;'));
        self::tap('.tabbar a[href="/obrok19/mapa"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/obrok19/mapa"]\'); return s && !s.hidden && ' . self::ROOT . '.dataset.view === "plan";');
    }
}
