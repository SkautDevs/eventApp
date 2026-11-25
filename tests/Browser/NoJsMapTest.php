<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Místa without JavaScript, on obrok19 and obrok27, which both carry Google's map and the
 * handbook's plan: each view pill is a link to its view's address, `/mapa` and `/mapa/plan`,
 * whose page the server paints on that view, and the plan's zoom pair, which only the
 * script drives, is hidden. The lane is hermetic, so Google's iframe stays a blank box;
 * its pane is still the one shown.
 */
#[Group('browser')]
final class NoJsMapTest extends NoJsTestCase
{
    /** @return array<string, array{string}> */
    public static function events(): array
    {
        return ['obrok19' => ['obrok19'], 'obrok27' => ['obrok27']];
    }

    #[DataProvider('events')]
    public function testMapaAndPlanSwitchByTheirPills(string $slug): void
    {
        self::visit('/' . $slug . '/mapa');
        self::assertShows($slug, 'google');

        self::follow('#map-tab-plan');

        self::assertSame('/' . $slug . '/mapa/plan', self::path(), $slug . ': the Plán pill is a link to its address');
        self::assertShows($slug, 'plan');
        self::assertTrue(self::displayed('.map-pane-plan .plan-img'), $slug . ': the plan image');

        self::follow('#map-tab-google');

        self::assertSame('/' . $slug . '/mapa', self::path(), $slug . ': the Mapa pill is a link back');
        self::assertShows($slug, 'google');
    }

    #[DataProvider('events')]
    public function testThePlanZoomIsHidden(string $slug): void
    {
        self::visit('/' . $slug . '/mapa/plan');

        self::assertTrue(self::displayed('.map-pane-plan .plan-img'), $slug . ': the plan is on show');
        self::assertFalse(self::displayed('.plan-zoom'), $slug . ': nothing zooms the plan without a script');
    }

    /** The page paints $view: its pane alone is shown, and its pill is selected and the current page. */
    private static function assertShows(string $slug, string $view): void
    {
        $other = $view === 'google' ? 'plan' : 'google';
        self::assertSame($view, self::attribute('.mapview', 'data-view'), $slug . ': the page paints ' . $view);
        self::assertTrue(self::displayed('.map-pane-' . $view), $slug . ': the ' . $view . ' pane');
        self::assertFalse(self::displayed('.map-pane-' . $other), $slug . ' on ' . $view . ': the ' . $other . ' pane');
        self::assertSame('page', self::attribute('#map-tab-' . $view, 'aria-current'), $slug . ': the ' . $view . ' pill is the current page');
        self::assertNull(self::attribute('#map-tab-' . $other, 'aria-current'), $slug . ': the ' . $other . ' pill');
    }
}
