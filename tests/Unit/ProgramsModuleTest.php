<?php

declare(strict_types=1);

namespace Tests\Unit;

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

    /**
     * A programme is drawn on every calendar day it overlaps, each piece clipped to its
     * own day, so a three-day programme is a bar on three pages rather than one bar
     * clipped at 24 hours on the first of them.
     */
    public function testAProgrammeIsCutIntoOnePiecePerDayItRuns(): void
    {
        $segments = self::call('segments', self::program(1, '2027-06-03 10:00:00', '2027-06-05 08:00:00'));

        self::assertSame(['2027-06-03', '2027-06-04', '2027-06-05'], array_column($segments, 'day'));
        self::assertSame(
            [['2027-06-03 10:00', '2027-06-04 00:00'], ['2027-06-04 00:00', '2027-06-05 00:00'], ['2027-06-05 00:00', '2027-06-05 08:00']],
            array_map(static fn (array $s): array => [date('Y-m-d H:i', $s['start']), date('Y-m-d H:i', $s['end'])], $segments),
        );
    }

    /** An end at exactly midnight is the end of the evening, not a zero-width card on the next day. */
    public function testAProgrammeEndingAtMidnightStaysOnTheDayItStarts(): void
    {
        $segments = self::call('segments', self::program(1, '2027-06-03 23:45:00', '2027-06-04 00:00:00'));

        self::assertSame(['2027-06-03'], array_column($segments, 'day'));
        self::assertSame(strtotime('2027-06-04 00:00:00'), $segments[0]['end']);
    }

    /** A record of no length is still drawn once, on its day. */
    public function testAProgrammeOfNoLengthIsStillOnItsDay(): void
    {
        $segments = self::call('segments', self::program(1, '2027-06-03 10:00:00', '2027-06-03 10:00:00'));

        self::assertSame(['2027-06-03'], array_column($segments, 'day'));
    }

    /**
     * A typo'd `end` from kissj must not fill hundreds of pages: the pieces stop after
     * MAX_DRAWN_DAYS. The detail sheet still shows the record's own end.
     */
    public function testARecordClaimingToRunForMonthsIsDrawnOverABoundedNumberOfDays(): void
    {
        $segments = self::call('segments', self::program(1, '2027-06-03 10:00:00', '2027-12-24 10:00:00'));

        self::assertCount((new \ReflectionClassConstant(ProgramsModule::class, 'MAX_DRAWN_DAYS'))->getValue(), $segments);
    }

    /**
     * Every piece of a multi-day programme opens the same single sheet, carries the same
     * registered state and names the whole run; the sheet, and with it the deep link,
     * belongs to the page of the day it starts on.
     */
    public function testAMultiDayProgrammeIsOnePageADayAndOneSheet(): void
    {
        $long = self::program(1, '2027-06-03 20:00:00', '2027-06-05 02:00:00');
        $model = self::buildViewModel([$long, self::program(2, '2027-06-04 09:00:00', '2027-06-04 10:00:00')], [$long]);

        self::assertSame(['page-20270603', 'page-20270604', 'page-20270605'], array_column($model['pages'], 'key'));
        self::assertSame([1, 2], array_column($model['details'], 'id'));
        self::assertSame('page-20270603', $model['details'][0]['page']);

        $cards = array_values(array_filter(self::cardsOf($model['pages']), static fn (array $c): bool => $c['id'] === 1));
        self::assertCount(3, $cards);
        foreach ($cards as $card) {
            self::assertSame('čt 3. 6. 20:00 – so 5. 6. 2:00', $card['time']);
            self::assertTrue($card['registered']);
            self::assertFalse($card['dimmed']);
        }
        // each piece is clipped to its own day, so no page's axis runs past midnight
        self::assertSame(['20:00', '00:00', '00:00'], array_map(static fn (array $p): string => $p['ruler'][0]['label'], $model['pages']));
        self::assertSame([4, 24, 2], array_column($model['pages'], 'hours'));

        // the list keeps it once, under the day it starts on, and names the day it ends on
        self::assertSame(['day-20270603'], array_column($model['days'], 'key'));
        self::assertSame(['20:00'], array_column($model['days'][0]['items'], 'time'));
        self::assertSame(['do so 5. 6. 2:00'], array_column($model['days'][0]['items'], 'until'));
    }

    /**
     * Midnight does not count as another day in the labels either. A time range is written
     * as the toast writes it — no leading zero, an unspaced en dash — and only a range
     * between two dates is spaced.
     */
    public function testATimeLabelEndingAtMidnightNamesNoDay(): void
    {
        self::assertSame('23:45–0:00', self::call('timeRange', self::program(1, '2027-06-03 23:45:00', '2027-06-04 00:00:00')));
        self::assertSame('čt 3. 6. 23:45 – pá 4. 6. 0:30', self::call('timeRange', self::program(1, '2027-06-03 23:45:00', '2027-06-04 00:30:00')));
    }

    /** The sheet's Kdy and the register toast write one programme's time the same way. */
    public function testTheSheetAndTheToastWriteTheTimeAlike(): void
    {
        $program = self::program(1, '2027-06-03 08:00:00', '2027-06-03 22:00:00');

        self::assertSame('čt 3. 6. 8:00–22:00', self::call('whenLabel', $program));
        self::assertStringContainsString(' · 8:00–22:00', ProgramsModule::toastLine($program));
        self::assertSame('8:00–22:00', self::call('timeRange', $program));
    }

    // --- day grouping -------------------------------------------------------

    public function testTheListGroupsByDayAloneAndSortsEachDayByStart(): void
    {
        $days = self::call('buildDays', [
            self::program(1, '2027-06-04 09:00:00', '2027-06-04 10:00:00', name: 'Druhý den'),
            self::program(2, '2027-06-03 14:00:00', '2027-06-03 15:00:00', name: 'Odpoledne'),
            self::program(3, '2027-06-03 08:00:00', '2027-06-03 09:00:00', name: 'Ráno'),
        ], [3 => true], true);

        self::assertSame(['day-20270603', 'day-20270604'], array_column($days, 'key'));
        self::assertSame(['Ráno', 'Odpoledne'], array_column($days[0]['items'], 'name'));
        self::assertSame('čt 3. 6.', $days[0]['label']);
        $first = $days[0]['items'][0];
        self::assertSame('8:00', $first['time']);
        self::assertSame('do 9:00', $first['until']);
        self::assertSame('8:00–9:00', $first['range']);
        self::assertSame('Louka', $first['location']);
        self::assertTrue($first['registered']);
        self::assertFalse($first['dimmed']);
        // with the personal list read, what is not the participant's steps back
        self::assertFalse($days[0]['items'][1]['registered']);
        self::assertTrue($days[0]['items'][1]['dimmed']);
    }

    /**
     * kissj's personal list holds real registrations only: a preregistered programme
     * belongs to a role eventApp does not know, so it is never "not yours" — not dimmed in
     * Přehled nor on the timeline — and, absent from that list, not in Moje either.
     */
    public function testAPreregisteredProgrammeIsNeverDimmed(): void
    {
        $fixed = self::program(1, '2027-06-03 08:00:00', '2027-06-03 09:00:00') + ['preregistered' => true];
        $free = self::program(2, '2027-06-03 10:00:00', '2027-06-03 11:00:00') + ['preregistered' => false];

        $model = self::call('buildViewModel', [1 => ['id' => 1, 'title' => 'Blok']], [$fixed, $free], [], true);

        $dimmed = array_column($model['overview'][0]['items'], 'dimmed', 'id');
        self::assertSame([1 => false, 2 => true], $dimmed);
        self::assertSame([1 => false, 2 => true], array_column(self::cardsOf($model['pages']), 'dimmed', 'id'));
        self::assertSame([], $model['days']);
    }

    /** Without a personal list nothing is dimmed: "not yours" would be a guess. */
    public function testTheListDimsNothingWhenThePersonalListWasNotRead(): void
    {
        $days = self::call('buildDays', [self::program(1, '2027-06-03 08:00:00', '2027-06-03 09:00:00')], [], false);

        self::assertFalse($days[0]['items'][0]['registered']);
        self::assertFalse($days[0]['items'][0]['dimmed']);
    }

    /**
     * Přehled is every programme of the event, by day, with the participant's own marked and
     * the rest dimmed; Moje (`days`) is the participant's alone. Measured on korbo26's fixtures.
     */
    public function testTheOverviewHoldsEveryProgrammeAndTheListOnlyTheParticipants(): void
    {
        $fixtures = dirname(__DIR__, 2) . '/events/korbo26/fixtures';
        $provider = new \App\Program\StubProgramProvider($fixtures, sys_get_temp_dir() . '/eventapp-unused-' . bin2hex(random_bytes(4)));
        $all = $provider->getPrograms();
        $mineIds = json_decode((string) file_get_contents($fixtures . '/registered.json'), true)['1'];
        $mine = array_values(array_filter($all, static fn (array $p): bool => in_array($p['id'], $mineIds, true)));

        $model = self::call('buildViewModel', $provider->getSections(), $all, $mine, true);
        $preregistered = array_column(array_filter($all, static fn (array $p): bool => $p['preregistered']), 'id');
        self::assertSame([3, 36, 51], $preregistered);
        self::assertSame([], array_intersect($preregistered, $mineIds), 'kissj lists no preregistered programme as anybody\'s');

        $overviewIds = [];
        foreach ($model['overview'] as $day) {
            foreach ($day['items'] as $item) {
                $overviewIds[] = $item['id'];
                // grouped under the day it starts on
                self::assertSame($day['key'], 'day-' . (new \DateTimeImmutable($item['start']))->format('Ymd'), "programme {$item['id']}");
                // a preregistered programme is everybody's as far as eventApp can tell: never dimmed
                self::assertSame(!in_array($item['id'], $mineIds, true) && !in_array($item['id'], $preregistered, true), $item['dimmed'], "programme {$item['id']}");
                self::assertSame(in_array($item['id'], $mineIds, true), $item['registered'], "programme {$item['id']}");
            }
        }
        $allIds = array_column($all, 'id');
        sort($allIds);
        sort($overviewIds);
        self::assertSame($allIds, $overviewIds);
        self::assertSame(['day-20260916', 'day-20260917', 'day-20260918', 'day-20260919', 'day-20260920'], array_column($model['overview'], 'key'));
        self::assertSame('day-20260916', $model['activeOverview']);

        $listIds = [];
        foreach ($model['days'] as $day) {
            array_push($listIds, ...array_column($day['items'], 'id'));
        }
        self::assertSame($mineIds, $listIds);
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
        self::assertSame('page-20270603', $model['activePage']);
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

        self::assertSame('9:00', $models['UTC']['days'][0]['items'][0]['time']);
        self::assertSame('do 10:30', $models['UTC']['days'][0]['items'][0]['until']);
        self::assertSame('čt 3. 6. 9:00–10:30', $models['UTC']['details'][0]['when']);
        // the axis handed to the client is an instant in the event's own zone
        self::assertSame('2027-06-03T09:00:00+02:00', $models['UTC']['pages'][0]['axisStart']);
        self::assertSame('2027-06-03T11:00:00+02:00', $models['UTC']['pages'][0]['axisEnd']);
        self::assertSame($models['UTC'], $models['Europe/Prague']);
        self::assertSame($models['UTC'], $models['America/New_York']);
    }

    // --- helpers ------------------------------------------------------------

    /** @return list<list<array>> */
    private static function pack(array $programs): array
    {
        $segments = array_map(static fn (array $p): array => self::call('segments', $p)[0], $programs);

        return self::call('packTracks', $segments, (int) strtotime('2027-06-03 00:00:00'), [], false);
    }

    /** @return array<string, mixed> */
    private static function buildViewModel(array $all, array $mine = []): array
    {
        $sections = [1 => ['id' => 1, 'title' => 'Sekce', 'subTitle' => null]];

        return self::call('buildViewModel', $sections, $all, $mine, $mine !== []);
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
        return count(self::cardsOf($pages));
    }

    /** @return list<array> every card on every page */
    private static function cardsOf(array $pages): array
    {
        $cards = [];
        foreach ($pages as $page) {
            foreach ($page['rows'] as $row) {
                foreach ($row['tracks'] as $track) {
                    array_push($cards, ...$track);
                }
            }
        }

        return $cards;
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
