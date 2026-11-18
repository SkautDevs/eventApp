<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Time\Zone;
use PHPUnit\Framework\TestCase;

final class ZoneTest extends TestCase
{
    public function testMidnightIsThePragueDayWhateverZoneTheInstantCarries(): void
    {
        $late = new \DateTimeImmutable('2027-05-20 23:30:00', new \DateTimeZone('UTC'));
        self::assertSame('2027-05-21T00:00:00+02:00', Zone::midnight($late)->format(DATE_ATOM));
    }
}
