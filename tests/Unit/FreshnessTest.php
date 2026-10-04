<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Program\Freshness;
use PHPUnit\Framework\TestCase;

final class FreshnessTest extends TestCase
{
    private static function prague(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Prague'));
    }

    public function testWithoutReadsItCarriesTheRequestTimeInPrague(): void
    {
        $freshness = new Freshness(new \DateTimeImmutable('2026-10-04 10:00:00', new \DateTimeZone('UTC')));

        self::assertSame(['fetchedAt' => '2026-10-04T12:00:00+02:00', 'stale' => false], $freshness->forView());
        self::assertNull($freshness->fetchedAt());
    }

    public function testItKeepsTheOldestFetchAndAnyStaleness(): void
    {
        $freshness = new Freshness(self::prague('2026-10-04 12:00:00'));

        $freshness->note(self::prague('2026-10-04 11:58:00'), false);
        $freshness->note(self::prague('2026-10-04 09:00:00'), true);
        $freshness->note(self::prague('2026-10-04 11:59:00'), false);

        self::assertSame(['fetchedAt' => '2026-10-04T09:00:00+02:00', 'stale' => true], $freshness->forView());
        self::assertTrue($freshness->isStale());
    }

    public function testResetForgetsThePreviousRequest(): void
    {
        $freshness = new Freshness(self::prague('2026-10-04 12:00:00'));
        $freshness->note(self::prague('2026-10-04 09:00:00'), true);
        $before = $freshness->generation();

        $freshness->reset(self::prague('2026-10-04 12:05:00'));

        self::assertSame(['fetchedAt' => '2026-10-04T12:05:00+02:00', 'stale' => false], $freshness->forView());
        self::assertNull($freshness->fetchedAt());
        self::assertFalse($freshness->isStale());
        self::assertNotSame($before, $freshness->generation());
    }
}
