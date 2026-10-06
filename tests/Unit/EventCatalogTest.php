<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventCatalog;
use PHPUnit\Framework\TestCase;

final class EventCatalogTest extends TestCase
{
    private function catalog(): EventCatalog
    {
        return new EventCatalog(dirname(__DIR__) . '/fixtures/events');
    }

    public function testHasOnlyDirectoriesWithAConfig(): void
    {
        self::assertTrue($this->catalog()->has('minimal'));
        self::assertFalse($this->catalog()->has('nope'));
        self::assertFalse($this->catalog()->has('../events'));
        self::assertFalse($this->catalog()->has("minimal\n"));
    }

    public function testListedSplitsUpcomingFromPastAndSkipsUnlisted(): void
    {
        $listed = $this->catalog()->listed(new \DateTimeImmutable('2026-09-30'));

        self::assertSame(['listed-future'], array_map(fn ($e) => $e->slug, $listed['upcoming']));
        self::assertSame(['listed-past'], array_map(fn ($e) => $e->slug, $listed['past']));
    }

    public function testAnEventEndingTodayIsStillUpcoming(): void
    {
        $listed = $this->catalog()->listed(new \DateTimeImmutable('2025-06-08'));

        self::assertContains('listed-past', array_map(fn ($e) => $e->slug, $listed['upcoming']));
    }

    public function testSlugsListsEveryEventIncludingTheUnlisted(): void
    {
        $catalog = new EventCatalog(dirname(__DIR__, 2) . '/events');

        self::assertSame(['korbo26', 'miquik26', 'navigamus25', 'obrok19', 'obrok27'], $catalog->slugs());
    }
}
