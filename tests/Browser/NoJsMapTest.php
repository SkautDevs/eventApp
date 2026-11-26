<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Místa without JavaScript, on obrok19 and obrok27, which both carry Google's map and the
 * handbook's plan: each view pill is a link to its view's address, `/mapa` and `/mapa/plan`,
 * whose page the server paints on that view, and the plan's zoom pair, which only the
 * script drives, is hidden. The lane is hermetic, so Google's iframe stays a blank box;
 * its pane is still the one shown. An address naming a place (`?misto=<id>`) shows that
 * place's marker on the plan, scrolled to by its fragment, and centres Google's map on it.
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

    /**
     * Programme 11 is at Plaza, which lies on obrok27's plan. Its sheet's pin leads to
     * `/obrok27/mapa/plan?misto=<id>#misto-<id>`: that marker alone is displayed, inside
     * the plan's box.
     */
    public function testThePlanShowsThePlaceItIsOpenedOn(): void
    {
        $href = self::plazaPin();
        self::assertSame(1, preg_match('~^/obrok27/mapa/plan\?misto=(\d+)#misto-\1$~', $href, $m), 'the pin goes to Plán: ' . $href);
        $id = $m[1];
        self::visit($href);

        self::assertTrue(self::displayed('#misto-' . $id), 'the place\'s marker');
        $markers = self::$browser->findElements(WebDriverBy::cssSelector('.plan-marker'));
        self::assertGreaterThan(1, count($markers));
        foreach ($markers as $marker) {
            if ($marker->getAttribute('id') !== 'misto-' . $id) {
                self::assertFalse($marker->isDisplayed(), 'every other marker is hidden: ' . $marker->getAttribute('id'));
            }
        }

        $box = self::$browser->findElement(WebDriverBy::cssSelector('.plan'));
        $marker = self::$browser->findElement(WebDriverBy::cssSelector('#misto-' . $id));
        $boxAt = $box->getLocation();
        $boxSize = $box->getSize();
        $at = $marker->getLocation();
        $size = $marker->getSize();
        self::assertGreaterThanOrEqual($boxAt->getX(), $at->getX(), 'the marker is inside the plan: left');
        self::assertGreaterThanOrEqual($boxAt->getY(), $at->getY(), 'top');
        self::assertLessThanOrEqual($boxAt->getX() + $boxSize->getWidth(), $at->getX() + $size->getWidth(), 'right');
        self::assertLessThanOrEqual($boxAt->getY() + $boxSize->getHeight(), $at->getY() + $size->getHeight(), 'bottom');
    }

    /** Mapa opened on a place is centred on it; its Plán pill keeps the place and jumps to the marker. */
    public function testMapaIsCentredOnThePlace(): void
    {
        parse_str((string) parse_url(self::plazaPin(), PHP_URL_QUERY), $query);
        $id = $query['misto'] ?? null;
        self::assertIsString($id);
        self::visit('/obrok27/mapa?misto=' . $id);

        $lat = self::attribute('[data-map-places] [data-place="' . $id . '"]', 'data-lat');
        $lng = self::attribute('[data-map-places] [data-place="' . $id . '"]', 'data-lng');
        self::assertMatchesRegularExpression('/^-?\d+\.\d{6}$/', (string) $lat);
        self::assertMatchesRegularExpression('/^-?\d+\.\d{6}$/', (string) $lng);
        self::assertStringContainsString('&ll=' . $lat . ',' . $lng . '&z=18', (string) self::attribute('.map-pane-google iframe', 'src'));

        self::follow('#map-tab-plan');

        self::assertSame('/obrok27/mapa/plan?misto=' . $id . '#misto-' . $id, self::path());
        self::assertTrue(self::displayed('#misto-' . $id), 'the place\'s marker');
    }

    /** The path, query and hash of the pin in the sheet of obrok27's programme 11, at Plaza. */
    private static function plazaPin(): string
    {
        self::visit('/obrok27/programy/prehled');
        $url = (string) self::attribute('.sheet-body[data-pg-detail="11"] .place-pin', 'href');
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        self::assertIsString($path, 'programme 11 has a pin: ' . $url);

        return $path . (is_string($query) ? '?' . $query : '') . (is_string($fragment) ? '#' . $fragment : '');
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
