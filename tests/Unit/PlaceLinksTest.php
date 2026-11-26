<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use App\Module\Map\PlaceLinks;
use App\Program\Place;
use PHPUnit\Framework\TestCase;

/**
 * Where a place's pin leads (Plan call 10): Plán when the place lies on the plan, else
 * Mapa when the event has Google's map, else nowhere; never for a place without GPS.
 */
final class PlaceLinksTest extends TestCase
{
    private static function links(string $slug, bool $fixtureEvent = true): PlaceLinks
    {
        $dir = $fixtureEvent ? dirname(__DIR__) . '/fixtures/events' : dirname(__DIR__, 2) . '/events';

        return new PlaceLinks(EventConfig::load($dir, $slug));
    }

    public function testAPlaceInsideThePlanOpensThePlan(): void
    {
        $links = self::links('map-places');

        self::assertTrue($links->possible());
        self::assertSame('map-plan', $links->route(new Place(1, 'Brána', 50.005, 15.005)));
    }

    public function testAPlaceOutsideThePlanOpensTheMapWhenThereIsOne(): void
    {
        self::assertSame('map', self::links('map-places')->route(new Place(3, 'Daleko', 51.0, 15.0)));
    }

    public function testAPlaceOutsideAPlanOnlyEventHasNoPin(): void
    {
        $links = self::links('map-places-plan');

        self::assertTrue($links->possible());
        self::assertNull($links->route(new Place(3, 'Daleko', 51.0, 15.0)));
        self::assertSame('map-plan', $links->route(new Place(1, 'Brána', 50.005, 15.005)));
    }

    public function testAPlaceWithoutGpsHasNoPin(): void
    {
        self::assertNull(self::links('map-places')->route(new Place(2, 'Celý tábor', null, null)));
    }

    public function testAnEmbedOnlyEventOpensTheMap(): void
    {
        $links = self::links('map-embed');

        self::assertTrue($links->possible());
        self::assertSame('map', $links->route(new Place(1, 'A', 50.0, 15.0)));
    }

    /** A plan without bounds places nothing on it: map-plan-only has neither bounds nor an embed. */
    public function testAPlanWithoutBoundsHasNoPin(): void
    {
        $links = self::links('map-plan-only');

        self::assertFalse($links->possible());
        self::assertNull($links->route(new Place(1, 'A', 50.0, 15.0)));
    }

    public function testNoMapNoPin(): void
    {
        $links = self::links('korbo26', fixtureEvent: false);

        self::assertFalse($links->possible());
        self::assertNull($links->route(new Place(1, 'Louka', 50.0, 15.0)));
    }
}
