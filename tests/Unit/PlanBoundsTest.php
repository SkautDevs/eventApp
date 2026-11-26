<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Event\PlanBounds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** map.planBounds: the plan image's edges in degrees, and where a point falls on the image. */
final class PlanBoundsTest extends TestCase
{
    public function testValidBoundsPlaceAPoint(): void
    {
        $bounds = PlanBounds::fromArray(['north' => 50.01, 'south' => 50.00, 'west' => 15.00, 'east' => 15.02]);

        self::assertNotNull($bounds);
        self::assertEqualsWithDelta(25.0, $bounds->x(15.005), 1e-9);
        self::assertEqualsWithDelta(50.0, $bounds->y(50.005), 1e-9);
        self::assertTrue($bounds->contains(50.005, 15.005));
        // the edges are inclusive: the north-west corner is on the plan
        self::assertTrue($bounds->contains(50.01, 15.0));
        self::assertFalse($bounds->contains(51.0, 15.0));
    }

    public function testIntegerDegreesCount(): void
    {
        $bounds = PlanBounds::fromArray(['north' => 51, 'south' => 50, 'west' => 15, 'east' => 16]);

        self::assertNotNull($bounds);
        self::assertSame(51.0, $bounds->north);
    }

    /** @return array<string, array{mixed}> */
    public static function invalid(): array
    {
        $valid = ['north' => 50.01, 'south' => 50.00, 'west' => 15.00, 'east' => 15.02];

        return [
            'north not above south' => [['south' => 50.01] + $valid],
            'east not right of west' => [['west' => 15.02] + $valid],
            'a missing key' => [array_diff_key($valid, ['east' => true])],
            'a string' => [['north' => '50'] + $valid],
            'a latitude out of range' => [['north' => 95] + $valid],
            'a longitude out of range' => [['east' => 181] + $valid],
            'a bool' => [['north' => true] + $valid],
            'not a map' => ['50,15'],
        ];
    }

    #[DataProvider('invalid')]
    public function testInvalidBoundsAreNull(mixed $raw): void
    {
        self::assertNull(PlanBounds::fromArray($raw));
    }
}
