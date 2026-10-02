<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tools\Fixtures\AllDayClamp;

final class AllDayClampTest extends TestCase
{
    private static function p(int $id, string $start, string $end): array
    {
        return ['id' => $id, 'start' => ['date' => $start], 'end' => ['date' => $end]];
    }

    public function testASingleAllDayEntryIsClampedToItsDay(): void
    {
        $out = AllDayClamp::apply([self::p(1, '2026-09-16 00:00:00', '2026-09-16 23:59:00')], '08:00', '22:00');

        self::assertSame([self::p(1, '2026-09-16 08:00:00', '2026-09-16 22:00:00')], $out);
    }

    public function testAMultiDayAllDayEntryKeepsItsIdAndSpan(): void
    {
        $out = AllDayClamp::apply([self::p(2, '2026-09-16 00:00:00', '2026-09-18 23:59:00')], '08:00', '22:00');

        // one programme still, from the first day's morning to the last day's evening:
        // ProgramsModule::segments() then draws it on every day it overlaps
        self::assertSame([self::p(2, '2026-09-16 08:00:00', '2026-09-18 22:00:00')], $out);
    }

    public function testTimedEntriesAreUntouched(): void
    {
        $timed = [self::p(3, '2026-09-16 23:45:00', '2026-09-17 00:00:00'), self::p(4, '2026-09-16 10:00:00', '2026-09-16 16:00:00')];

        self::assertSame($timed, AllDayClamp::apply($timed, '08:00', '22:00'));
    }

    public function testAZeroLengthEntryAtMidnightIsUntouched(): void
    {
        $entry = [self::p(5, '2026-09-16 00:00:00', '2026-09-16 00:00:00')];

        self::assertSame($entry, AllDayClamp::apply($entry, '08:00', '22:00'));
    }

    public function testATimedEntryStartingAtMidnightButNotEndingAtTheDayEndIsUntouched(): void
    {
        $entry = [self::p(6, '2026-09-16 00:00:00', '2026-09-16 06:00:00')];

        self::assertSame($entry, AllDayClamp::apply($entry, '08:00', '22:00'));
    }
}
