<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Program\Place;
use App\Program\Places;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlacesTest extends TestCase
{
    public function testAPlaceWithBothCoordinatesHasGps(): void
    {
        $places = Places::fromKissj([['id' => 7, 'name' => 'Žlutý les', 'lat' => 50.340847, 'lng' => 15.935361]]);

        self::assertEquals([7 => new Place(7, 'Žlutý les', 50.340847, 15.935361)], $places);
        self::assertTrue($places[7]->hasGps());
    }

    /** kissj serialises a round coordinate as a JSON integer. */
    public function testIntegerCoordinatesAreFloats(): void
    {
        $place = Places::fromKissj([['id' => 1, 'name' => 'A', 'lat' => 50, 'lng' => 15]])[1];

        self::assertSame(50.0, $place->lat);
        self::assertSame(15.0, $place->lng);
        self::assertTrue($place->hasGps());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function withoutGps(): iterable
    {
        yield 'no latitude' => [['lat' => null, 'lng' => 15]];
        yield 'latitude out of range' => [['lat' => 95, 'lng' => 15]];
        yield 'longitude out of range' => [['lat' => 50, 'lng' => 181]];
        yield 'strings' => [['lat' => '50', 'lng' => '15']];
        yield 'a bool' => [['lat' => true, 'lng' => 15]];
        yield 'no longitude key' => [['lat' => 50]];
    }

    /** @param array<string, mixed> $coordinates */
    #[DataProvider('withoutGps')]
    public function testOneMissingOrOutOfRangeCoordinateMeansNoGps(array $coordinates): void
    {
        $place = Places::fromKissj([['id' => 1, 'name' => 'A', ...$coordinates]])[1];

        self::assertNull($place->lat);
        self::assertNull($place->lng);
        self::assertFalse($place->hasGps());
    }

    public function testTheRangesIncludeTheirEdges(): void
    {
        $place = Places::fromKissj([['id' => 1, 'name' => 'A', 'lat' => -90, 'lng' => 180.0]])[1];

        self::assertSame([-90.0, 180.0], [$place->lat, $place->lng]);
    }

    public function testBadRecordsAreSkippedAndNothingThrows(): void
    {
        self::assertSame([], Places::fromKissj('x'));
        self::assertSame([], Places::fromKissj(['a' => 1]));

        $places = Places::fromKissj([
            ['id' => 0, 'name' => 'A'],
            ['id' => '2', 'name' => 'B'],
            ['id' => 3, 'name' => ''],
            ['id' => 4],
            'x',
            ['id' => 5, 'name' => 'E'],
        ]);

        self::assertSame([5], array_keys($places));
        self::assertFalse($places[5]->hasGps());
    }

    public function testADuplicateIdKeepsTheFirst(): void
    {
        $places = Places::fromKissj([['id' => 1, 'name' => 'A'], ['id' => 1, 'name' => 'B']]);

        self::assertSame([1], array_keys($places));
        self::assertSame('A', $places[1]->name);
    }

    public function testTheSourcesOrderIsKept(): void
    {
        self::assertSame([9, 2, 5], array_keys(Places::fromKissj([['id' => 9, 'name' => 'C'], ['id' => 2, 'name' => 'A'], ['id' => 5, 'name' => 'B']])));
    }

    public function testTheRowRoundTrips(): void
    {
        foreach ([new Place(7, 'Žlutý les', 50.340847, 15.935361), new Place(8, 'Celý tábor', null, null)] as $place) {
            self::assertSame(['id' => $place->id, 'name' => $place->name, 'lat' => $place->lat, 'lng' => $place->lng], $place->toArray());
            self::assertEquals([$place->id => $place], Places::fromKissj([$place->toArray()]));
        }
    }
}
