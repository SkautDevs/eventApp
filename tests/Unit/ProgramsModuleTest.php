<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use App\Module\ProgramsModule;
use PHPUnit\Framework\TestCase;

/**
 * The Program screen's view model is built entirely in PHP: the module takes arrays
 * from the provider and hands Twig a finished shape. Everything below exercises those
 * pure helpers directly — through reflection, because they are private statics and the
 * alternative is asserting on rendered HTML, which is how the packing, the grouping and
 * the date handling stayed uncovered.
 */
final class ProgramsModuleTest extends TestCase
{
    // --- interval packing ---------------------------------------------------

    public function testProgrammesThatDoNotOverlapShareOneTrack(): void
    {
        $tracks = self::pack([
            self::program(1, '2027-06-03 09:00:00', '2027-06-03 10:00:00'),
            self::program(2, '2027-06-03 10:00:00', '2027-06-03 11:00:00'),
        ]);

        self::assertCount(1, $tracks);
        self::assertCount(2, $tracks[0]);
        self::assertNoOverlap($tracks);
    }

    public function testOverlappingProgrammesGetATrackEach(): void
    {
        $tracks = self::pack([
            self::program(1, '2027-06-03 09:00:00', '2027-06-03 11:00:00'),
            // fully contained in the first one, which is the case a naive packer misses
            self::program(2, '2027-06-03 09:30:00', '2027-06-03 10:00:00'),
            self::program(3, '2027-06-03 10:00:00', '2027-06-03 12:00:00'),
        ]);

        self::assertCount(2, $tracks);
        self::assertNoOverlap($tracks);
    }

    /**
     * BUG-13: the packer used to free a track at the record's own end while the card was
     * drawn to a minimum width of a minute, so anything shorter than that had the next
     * programme drawn underneath its bar.
     */
    public function testAProgrammeTooShortToDrawDoesNotGetTheNextOneUnderneathIt(): void
    {
        $tracks = self::pack([
            self::program(1, '2027-06-03 10:00:00', '2027-06-03 10:00:30'),
            self::program(2, '2027-06-03 10:00:30', '2027-06-03 11:00:00'),
        ]);

        self::assertNoOverlap($tracks);
        self::assertCount(2, $tracks, 'the widened bar and its neighbour were packed into one track');
        // the widened bar is a full minute wide, which is what makes the two collide
        self::assertSame(round(60 / 3600, 4), $tracks[0][0]['span']);
    }

    /** A record claiming to run for a week is clipped to a day rather than stretching the axis. */
    public function testAnAbsurdlyLongProgrammeIsClippedToADay(): void
    {
        $tracks = self::pack([self::program(1, '2027-06-03 10:00:00', '2027-06-10 10:00:00')]);

        self::assertSame(24.0, $tracks[0][0]['span']);
    }

    // --- day grouping -------------------------------------------------------

    public function testTheListGroupsByDayAloneAndSortsEachDayByStart(): void
    {
        $days = self::call('buildDays', [
            self::program(1, '2027-06-04 09:00:00', '2027-06-04 10:00:00', name: 'Druhý den'),
            self::program(2, '2027-06-03 14:00:00', '2027-06-03 15:00:00', name: 'Odpoledne'),
            self::program(3, '2027-06-03 08:00:00', '2027-06-03 09:00:00', name: 'Ráno'),
        ]);

        self::assertSame(['day-20270603', 'day-20270604'], array_column($days, 'key'));
        self::assertSame(['Ráno', 'Odpoledne'], array_column($days[0]['items'], 'name'));
        self::assertSame('čt 3. 6.', $days[0]['label']);
        self::assertSame('08:00 – 09:00', $days[0]['items'][0]['time']);
    }

    // --- the page the screen opens on ---------------------------------------

    public function testTheScreenOpensOnTodayWhileTheEventIsRunning(): void
    {
        $pages = [
            ['key' => 'page-a', 'day' => date('Y-m-d', strtotime('-1 day'))],
            ['key' => 'page-b', 'day' => date('Y-m-d')],
        ];

        self::assertSame('page-b', self::call('activeKey', $pages));
    }

    public function testTheScreenOpensOnItsFirstPageOutsideTheEvent(): void
    {
        $pages = [['key' => 'page-a', 'day' => '2027-06-03'], ['key' => 'page-b', 'day' => '2027-06-04']];

        self::assertSame('page-a', self::call('activeKey', $pages));
        self::assertNull(self::call('activeKey', []));
    }

    // --- records the screen cannot draw -------------------------------------

    /**
     * BUG-7: `strtotime` answers false for a missing date, which used to become
     * 1970-01-01 — its own page, sorted first, and the page the screen opened on
     * whenever today was not in the event.
     */
    public function testProgrammesWithoutAParsableDateNeverReachTheScreen(): void
    {
        $model = self::buildViewModel([
            self::program(1, '2027-06-03 09:00:00', '2027-06-03 10:00:00', name: 'Dobrý'),
            self::program(2, '', '2027-06-03 12:00:00', name: 'Bez začátku'),
            self::program(3, '2027-06-03 13:00:00', '', name: 'Bez konce'),
            self::program(4, 'není datum', 'taky ne', name: 'Nesmysl'),
        ]);

        self::assertCount(1, $model['pages']);
        self::assertSame('page-20270603-1', $model['activePage']);
        self::assertSame([1], array_column($model['details'], 'id'));
    }

    /** The same guard is what keeps a null date from being a TypeError five frames in. */
    public function testANullDateIsADroppedRecordAndNotACrash(): void
    {
        $broken = self::program(2, '2027-06-03 09:00:00', '2027-06-03 10:00:00');
        $broken['start'] = null;
        unset($broken['end']);

        $model = self::buildViewModel([
            self::program(1, '2027-06-03 09:00:00', '2027-06-03 10:00:00'),
            $broken,
        ]);

        self::assertSame([1], array_column($model['details'], 'id'));
    }

    /**
     * BUG-14: two records sharing an id rendered two cards and one detail body, so both
     * cards opened the second one's sheet — and the morph, which keys on the id, re-created
     * the duplicate instead of matching it. The first occurrence wins.
     */
    public function testDuplicateIdsCollapseToTheFirstRecord(): void
    {
        $model = self::buildViewModel([
            self::program(7, '2027-06-03 09:00:00', '2027-06-03 10:00:00', name: 'První'),
            self::program(7, '2027-06-03 11:00:00', '2027-06-03 12:00:00', name: 'Druhý'),
        ]);

        self::assertCount(1, $model['details']);
        self::assertSame('První', $model['details'][0]['name']);
        self::assertSame(1, self::countCards($model['pages']));
    }

    // --- time ---------------------------------------------------------------

    /**
     * The provider hands the module naive local datetimes — the event's own wall clock —
     * and the module reads and formats them with the same zone, so what the reader sees is
     * what the record says whatever zone the server happens to run in. A shift introduced
     * anywhere in here is a shift on the screen, and nothing else in the suite would see it.
     */
    public function testTheProgrammeClockIsTheEventsOwnInAnyServerTimezone(): void
    {
        $programs = [self::program(1, '2027-06-03 09:00:00', '2027-06-03 10:30:00')];

        $models = [];
        foreach (['UTC', 'Europe/Prague', 'America/New_York'] as $zone) {
            $models[$zone] = self::withTimezone($zone, static fn (): array => self::buildViewModel($programs, $programs));
        }

        self::assertSame('09:00 – 10:30', $models['UTC']['days'][0]['items'][0]['time']);
        self::assertSame('čt 3. 6. 09:00 – 10:30', $models['UTC']['details'][0]['when']);
        self::assertSame($models['UTC'], $models['Europe/Prague']);
        self::assertSame($models['UTC'], $models['America/New_York']);
    }

    // --- helpers ------------------------------------------------------------

    /** @return list<list<array>> */
    private static function pack(array $programs): array
    {
        return self::call('packTracks', $programs, (int) strtotime('2027-06-03 00:00:00'), [], false);
    }

    /** @return array<string, mixed> */
    private static function buildViewModel(array $all, array $mine = []): array
    {
        $event = EventConfig::load(dirname(__DIR__) . '/fixtures/events', 'programs');

        return self::call('buildViewModel', $event, $all, $mine, $mine !== []);
    }

    private static function program(int $id, string $start, string $end, string $location = 'Louka', string $name = 'Program'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'section' => ['id' => 1],
            'start' => ['date' => $start],
            'end' => ['date' => $end],
            'location' => $location,
        ];
    }

    /** No two cards in one track may cover the same stretch of the axis. */
    private static function assertNoOverlap(array $tracks): void
    {
        foreach ($tracks as $index => $cards) {
            usort($cards, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);
            for ($i = 1; $i < count($cards); $i++) {
                self::assertGreaterThanOrEqual(
                    $cards[$i - 1]['offset'] + $cards[$i - 1]['span'],
                    $cards[$i]['offset'],
                    sprintf('track %d draws #%d on top of #%d', $index, $cards[$i]['id'], $cards[$i - 1]['id']),
                );
            }
        }
    }

    private static function countCards(array $pages): int
    {
        $count = 0;
        foreach ($pages as $page) {
            foreach ($page['rows'] as $row) {
                foreach ($row['tracks'] as $track) {
                    $count += count($track);
                }
            }
        }

        return $count;
    }

    private static function withTimezone(string $zone, callable $body): mixed
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set($zone);

        try {
            return $body();
        } finally {
            date_default_timezone_set($previous);
        }
    }

    private static function call(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod(ProgramsModule::class, $method))->invoke(null, ...$arguments);
    }
}
