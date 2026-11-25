<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use App\Module\Home\HomeModel;
use App\Module\Home\View\HomeView;
use App\Program\Programme;
use App\Program\RegistrationWindow;
use App\Program\Section;
use App\Push\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HomeModelTest extends TestCase
{
    private static function prague(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Prague'));
    }

    private static function obrok27(): EventConfig
    {
        return EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok27');
    }

    private static function noWindow(): RegistrationWindow
    {
        return new RegistrationWindow(null, null);
    }

    /** @param list<array> $news @param list<array> $mine programme rows @param array<int, array> $sections section rows */
    private static function build(
        string $now,
        ?EventConfig $event = null,
        ?RegistrationWindow $window = null,
        array $news = [],
        array $mine = [],
        array $sections = [],
        bool $paired = false,
    ): HomeView {
        return HomeModel::build(
            $event ?? self::obrok27(),
            $news,
            array_map(static fn(array $row): Programme => Programme::fromArray($row) ?? throw new \LogicException('no programme'), $mine),
            array_map(Section::fromArray(...), $sections),
            $paired,
            $window ?? self::noWindow(),
            self::prague($now),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function phases(): iterable
    {
        yield 'the day before' => ['2027-05-18 23:59:59', 'before'];
        yield 'long before' => ['2026-11-07 12:00:00', 'before'];
        yield 'the first day' => ['2027-05-19 00:00:00', 'during'];
        yield 'the middle' => ['2027-05-21 10:00:00', 'during'];
        yield 'the last day' => ['2027-05-23 23:59:59', 'during'];
        yield 'the day after' => ['2027-05-24 00:00:00', 'after'];
    }

    #[DataProvider('phases')]
    public function testThePhaseFollowsThePragueCalendar(string $now, string $phase): void
    {
        self::assertSame($phase, self::build($now)->phase);
    }

    public function testThePhaseIsReadInPragueWhateverZoneNowArrivesIn(): void
    {
        // 22:30 UTC on the 18th is half past midnight on the 19th in Prague
        $model = HomeModel::build(self::obrok27(), [], [], [], false, self::noWindow(), new \DateTimeImmutable('2027-05-18 22:30:00', new \DateTimeZone('UTC')));

        self::assertSame('during', $model->phase);
    }

    public function testAnEventWithoutDatesHasNoPhase(): void
    {
        $model = self::build('2027-05-21 10:00:00', EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok19'));

        self::assertNull($model->phase);
        self::assertNull($model->countdown);
    }

    public function testTodaysLabelIsTheFullWeekdayCapitalised(): void
    {
        self::assertSame('Pátek 21. 5.', self::build('2027-05-21 10:00:00')->todayLabel);
        self::assertSame('Úterý 18. 5.', self::build('2027-05-18 10:00:00')->todayLabel);
        self::assertSame('Čtvrtek 20. 5.', self::build('2027-05-20 10:00:00')->todayLabel);
    }

    public function testTheCountdownCountsPragueDaysToTheStart(): void
    {
        self::assertSame(
            ['days' => 193, 'start' => '2027-05-19', 'range' => '19.–23. 5. 2027'],
            get_object_vars(self::build('2026-11-07 23:30:00')->countdown),
        );
        self::assertSame(1, self::build('2027-05-18 00:00:01')->countdown->days);
        self::assertNull(self::build('2027-05-19 08:00:00')->countdown);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function ranges(): iterable
    {
        yield 'one month' => ['2027-05-19', '2027-05-23', '19.–23. 5. 2027'];
        yield 'two months' => ['2027-04-30', '2027-05-02', '30. 4.–2. 5. 2027'];
        yield 'two years' => ['2027-12-30', '2028-01-02', '30. 12. 2027–2. 1. 2028'];
        yield 'one day' => ['2027-05-19', '2027-05-19', '19. 5. 2027'];
    }

    #[DataProvider('ranges')]
    public function testTheRangeIsTheConfigsDates(string $start, string $end, string $expected): void
    {
        self::assertSame($expected, HomeModel::range($start, $end));
    }

    public function testTheWindowCardBeforeTheWindowNamesItsStart(): void
    {
        $window = new RegistrationWindow(self::prague('2027-03-15 18:00:00'), self::prague('2027-04-15 20:00:00'));

        self::assertSame(['state' => 'before', 'label' => '15. 3. 2027 · 18:00'], get_object_vars(self::build('2026-11-07 12:00:00', window: $window)->window));
    }

    public function testTheWindowCardWhileOpenNamesItsEnd(): void
    {
        $window = new RegistrationWindow(self::prague('2027-03-15 18:00:00'), self::prague('2027-04-15 20:00:00'));

        self::assertSame(['state' => 'open', 'label' => 'Do 15. 4. 2027'], get_object_vars(self::build('2027-04-01 12:00:00', window: $window)->window));
    }

    public function testTheWindowLabelsAreWrittenInPrague(): void
    {
        // 17:00 UTC in March is 18:00 in Prague
        $window = new RegistrationWindow(new \DateTimeImmutable('2027-03-15 17:00:00', new \DateTimeZone('UTC')), self::prague('2027-04-15 20:00:00'));

        self::assertSame('15. 3. 2027 · 18:00', self::build('2026-11-07 12:00:00', window: $window)->window->label);
    }

    public function testNoWindowCardWithoutAWindowOrAfterIt(): void
    {
        self::assertNull(self::build('2026-11-07 12:00:00')->window);
        $window = new RegistrationWindow(self::prague('2027-03-15 18:00:00'), self::prague('2027-04-15 20:00:00'));
        self::assertNull(self::build('2027-04-16 12:00:00', window: $window)->window);
    }

    public function testPairedIsPassedThrough(): void
    {
        self::assertTrue(self::build('2026-11-07 12:00:00', paired: true)->paired);
        self::assertFalse(self::build('2026-11-07 12:00:00')->paired);
    }

    public function testTheNewsAreTodaysOnly(): void
    {
        $item = static fn(int $id, string $at): Notification => new Notification(id: $id, when: '', at: '', sentAt: self::prague($at), title: 't', body: 'b', programme: null);
        $news = [$item(3, '2027-05-21 09:00:00'), $item(2, '2027-05-21 00:05:00'), $item(1, '2027-05-20 23:50:00')];

        self::assertSame([3, 2], array_column(self::build('2027-05-21 10:00:00', news: $news)->news, 'id'));
    }

    /** @return list<array> the participant's programmes in the internal shape */
    private static function mine(): array
    {
        $programme = static fn(int $id, string $start, string $end, ?string $location, ?int $section): array => [
            'id' => $id,
            'name' => 'Programme ' . $id,
            'section' => $section === null ? null : ['id' => $section],
            'start' => ['date' => $start],
            'end' => ['date' => $end],
            'location' => $location,
            'perex' => null,
        ];

        return [
            $programme(2, '2027-05-21 14:00:00', '2027-05-21 15:30:00', 'Modrý les', 2),
            $programme(1, '2027-05-21 11:00:00', '2027-05-21 12:30:00', 'Žlutý les', 1),
            $programme(3, '2027-05-21 20:00:00', '2027-05-21 22:00:00', null, null),
            $programme(4, '2027-05-21 22:00:00', '2027-05-21 23:00:00', 'Louka', 99),
        ];
    }

    public function testNextUpItemsCarryTheirTimesPlaceAndSection(): void
    {
        $sections = [
            1 => ['id' => 1, 'title' => 'Workshopy', 'subTitle' => null],
            2 => ['id' => 2, 'title' => 'Přednášky', 'subTitle' => 'odpoledne'],
        ];
        $nextUp = self::build('2027-05-21 12:00:00', mine: self::mine(), sections: $sections)->nextUp;

        self::assertSame([1, 2, 3, 4], array_column($nextUp->items, 'id'));
        // the first not yet over: 1 runs until 12:30
        self::assertSame(0, $nextUp->index);
        $byId = array_column($nextUp->items, null, 'id');
        self::assertSame(['11:00', 'do 12:30', 'Žlutý les', 'Workshopy'], [$byId[1]->time, $byId[1]->until, $byId[1]->location, $byId[1]->section]);
        self::assertSame(['14:00', 'do 15:30', 'Modrý les', 'Přednášky'], [$byId[2]->time, $byId[2]->until, $byId[2]->location, $byId[2]->section]);
        // no location, no section; a section the event does not list is none
        self::assertSame(['20:00', 'do 22:00', null, null], [$byId[3]->time, $byId[3]->until, $byId[3]->location, $byId[3]->section]);
        self::assertNull($byId[4]->section);
        // the section's id, for the card's deep link: 0 for none, and kept for one the event does not list
        self::assertSame([1, 2, 0, 99], array_column($nextUp->items, 'sectionId'));
        // the instants the shell compares with the device clock stay
        self::assertSame('2027-05-21T11:00:00+02:00', $byId[1]->start);
        self::assertSame('2027-05-21T12:30:00+02:00', $byId[1]->end);
    }

    public function testNextUpTimesAreWrittenAsTheProgramScreenWritesThem(): void
    {
        $mine = [[
            'id' => 5,
            'name' => 'Programme 5',
            'section' => null,
            'start' => ['date' => '2027-05-21 09:00:00'],
            'end' => ['date' => '2027-05-21 09:45:00'],
            'location' => null,
            'perex' => null,
        ]];
        $item = self::build('2027-05-21 08:00:00', mine: $mine)->nextUp->items[0];

        // no leading zero on the hour, as Times::CLOCK prints it
        self::assertSame(['9:00', 'do 9:45'], [$item->time, $item->until]);
    }

    public function testNoProgrammesNoNextUp(): void
    {
        self::assertNull(self::build('2027-05-21 12:00:00')->nextUp);
    }

    public function testEverythingOverLeavesTheItemsForTheDeviceClock(): void
    {
        $nextUp = self::build('2027-05-22 12:00:00', mine: self::mine())->nextUp;

        self::assertCount(4, $nextUp->items);
        self::assertNull($nextUp->index);
    }

    // --- the server's pick and leads, as www/js/home/nextup.js draws them ----------

    /** A card's lead line at $now, from its start, time, until and place. */
    private static function lead(string $start, string $time, string $until, ?string $location, string $now): string
    {
        return HomeModel::lead(new \DateTimeImmutable($start), $time, $until, $location, new \DateTimeImmutable($now));
    }

    /**
     * The same three cards at the same moment as tests/js/home.test.js's "nextLead reads as
     * HomeModel::lead at the same moment", which pins these three strings for the script: the
     * server's lead and the device clock's read alike.
     */
    public function testTheLeadLinesReadAsTheScriptWritesThem(): void
    {
        $now = '2026-09-19T10:30:00+02:00';

        self::assertSame('Právě · do 11:00 · Nástěnka', self::lead('2026-09-19T10:00:00+02:00', '10:00', 'do 11:00', 'Nástěnka', $now));
        self::assertSame('Za 45 min · 11:15', self::lead('2026-09-19T11:15:00+02:00', '11:15', 'do 12:00', null, $now));
        self::assertSame('14:00 · Les', self::lead('2026-09-19T14:00:00+02:00', '14:00', 'do 15:00', 'Les', $now));
    }

    /**
     * The boundaries, pinned for the script by tests/js/home.test.js's "nextLead on the
     * boundaries, as HomeModel::lead": a programme starting now runs, the minutes round up,
     * and exactly an hour ahead is still within the hour.
     */
    public function testTheLeadBoundariesMatchTheScript(): void
    {
        $start = '2026-09-19T10:00:00+02:00';

        self::assertSame('Právě · do 11:00', self::lead($start, '10:00', 'do 11:00', null, '2026-09-19T10:00:00+02:00'));
        self::assertSame('Za 1 min · 10:00', self::lead($start, '10:00', 'do 11:00', null, '2026-09-19T09:59:59.500+02:00'));
        self::assertSame('Za 60 min · 10:00', self::lead($start, '10:00', 'do 11:00', null, '2026-09-19T09:00:30+02:00'));
        self::assertSame('Za 60 min · 10:00', self::lead($start, '10:00', 'do 11:00', null, '2026-09-19T09:00:00+02:00'));
        self::assertSame('10:00', self::lead($start, '10:00', 'do 11:00', null, '2026-09-19T08:59:59+02:00'));
        // an empty place is none, as the script's falsy check
        self::assertSame('10:00', self::lead($start, '10:00', 'do 11:00', '', '2026-09-19T08:00:00+02:00'));
    }

    public function testTheFirstThreeNotOverAreShown(): void
    {
        $programme = static fn(int $id, string $start, string $end): array => [
            'id' => $id, 'name' => 'Programme ' . $id, 'section' => null,
            'start' => ['date' => $start], 'end' => ['date' => $end], 'location' => null, 'perex' => null,
        ];
        $mine = [
            $programme(1, '2027-05-21 08:00:00', '2027-05-21 09:00:00'),
            $programme(2, '2027-05-21 09:30:00', '2027-05-21 10:30:00'),
            $programme(3, '2027-05-21 11:00:00', '2027-05-21 12:00:00'),
            $programme(4, '2027-05-21 14:00:00', '2027-05-21 15:00:00'),
            $programme(5, '2027-05-21 16:00:00', '2027-05-21 17:00:00'),
        ];
        // 1 ends at 09:00, so it is over at now
        $nextUp = self::build('2027-05-21 10:00:00', mine: $mine)->nextUp;

        self::assertSame(3, HomeModel::NEXT_SHOWN);
        self::assertSame([1, 2, 3, 4, 5], array_column($nextUp->items, 'id'));
        self::assertSame([false, true, true, true, false], array_column($nextUp->items, 'shown'));
        // each shown card has its lead, a hidden one none
        self::assertSame(['', 'Právě · do 10:30', 'Za 60 min · 11:00', '14:00', ''], array_column($nextUp->items, 'lead'));
    }

    public function testEverythingOverShowsNoCard(): void
    {
        $nextUp = self::build('2027-05-22 12:00:00', mine: self::mine())->nextUp;

        self::assertSame([false, false, false, false], array_column($nextUp->items, 'shown'));
    }
}
