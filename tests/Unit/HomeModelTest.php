<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use App\Module\Home\HomeModel;
use App\Program\RegistrationWindow;
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

    /** @param list<array> $news @param list<array> $mine @param array<int, array> $sections */
    private static function build(
        string $now,
        ?EventConfig $event = null,
        ?RegistrationWindow $window = null,
        array $news = [],
        array $mine = [],
        array $sections = [],
        bool $paired = false,
    ): array {
        return HomeModel::build($event ?? self::obrok27(), $news, $mine, $sections, $paired, $window ?? self::noWindow(), self::prague($now));
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
        self::assertSame($phase, self::build($now)['phase']);
    }

    public function testThePhaseIsReadInPragueWhateverZoneNowArrivesIn(): void
    {
        // 22:30 UTC on the 18th is half past midnight on the 19th in Prague
        $model = HomeModel::build(self::obrok27(), [], [], [], false, self::noWindow(), new \DateTimeImmutable('2027-05-18 22:30:00', new \DateTimeZone('UTC')));

        self::assertSame('during', $model['phase']);
    }

    public function testAnEventWithoutDatesHasNoPhase(): void
    {
        $model = self::build('2027-05-21 10:00:00', EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok19'));

        self::assertNull($model['phase']);
        self::assertNull($model['countdown']);
    }

    public function testTodaysLabelIsTheFullWeekdayCapitalised(): void
    {
        self::assertSame('Pátek 21. 5.', self::build('2027-05-21 10:00:00')['todayLabel']);
        self::assertSame('Úterý 18. 5.', self::build('2027-05-18 10:00:00')['todayLabel']);
        self::assertSame('Čtvrtek 20. 5.', self::build('2027-05-20 10:00:00')['todayLabel']);
    }

    public function testTheCountdownCountsPragueDaysToTheStart(): void
    {
        self::assertSame(
            ['days' => 193, 'start' => '2027-05-19', 'range' => '19.–23. 5. 2027'],
            self::build('2026-11-07 23:30:00')['countdown'],
        );
        self::assertSame(1, self::build('2027-05-18 00:00:01')['countdown']['days']);
        self::assertNull(self::build('2027-05-19 08:00:00')['countdown']);
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

        self::assertSame(['state' => 'before', 'label' => '15. 3. 2027 · 18:00'], self::build('2026-11-07 12:00:00', window: $window)['window']);
    }

    public function testTheWindowCardWhileOpenNamesItsEnd(): void
    {
        $window = new RegistrationWindow(self::prague('2027-03-15 18:00:00'), self::prague('2027-04-15 20:00:00'));

        self::assertSame(['state' => 'open', 'label' => 'Do 15. 4. 2027'], self::build('2027-04-01 12:00:00', window: $window)['window']);
    }

    public function testTheWindowLabelsAreWrittenInPrague(): void
    {
        // 17:00 UTC in March is 18:00 in Prague
        $window = new RegistrationWindow(new \DateTimeImmutable('2027-03-15 17:00:00', new \DateTimeZone('UTC')), self::prague('2027-04-15 20:00:00'));

        self::assertSame('15. 3. 2027 · 18:00', self::build('2026-11-07 12:00:00', window: $window)['window']['label']);
    }

    public function testNoWindowCardWithoutAWindowOrAfterIt(): void
    {
        self::assertNull(self::build('2026-11-07 12:00:00')['window']);
        $window = new RegistrationWindow(self::prague('2027-03-15 18:00:00'), self::prague('2027-04-15 20:00:00'));
        self::assertNull(self::build('2027-04-16 12:00:00', window: $window)['window']);
    }

    public function testPairedIsPassedThrough(): void
    {
        self::assertTrue(self::build('2026-11-07 12:00:00', paired: true)['paired']);
        self::assertFalse(self::build('2026-11-07 12:00:00')['paired']);
    }

    public function testTheNewsAreTodaysOnly(): void
    {
        $item = static fn(int $id, string $at): array => ['id' => $id, 'when' => '', 'sentAt' => self::prague($at), 'title' => 't', 'body' => 'b', 'programme' => null];
        $news = [$item(3, '2027-05-21 09:00:00'), $item(2, '2027-05-21 00:05:00'), $item(1, '2027-05-20 23:50:00')];

        self::assertSame([3, 2], array_column(self::build('2027-05-21 10:00:00', news: $news)['news'], 'id'));
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
        $nextUp = self::build('2027-05-21 12:00:00', mine: self::mine(), sections: $sections)['nextUp'];

        self::assertSame([1, 2, 3, 4], array_column($nextUp['items'], 'id'));
        // the first not yet over: 1 runs until 12:30
        self::assertSame(0, $nextUp['index']);
        $byId = array_column($nextUp['items'], null, 'id');
        self::assertSame(['11:00', 'do 12:30', 'Žlutý les', 'Workshopy'], [$byId[1]['time'], $byId[1]['until'], $byId[1]['location'], $byId[1]['section']]);
        self::assertSame(['14:00', 'do 15:30', 'Modrý les', 'Přednášky'], [$byId[2]['time'], $byId[2]['until'], $byId[2]['location'], $byId[2]['section']]);
        // no location, no section; a section the event does not list is none
        self::assertSame(['20:00', 'do 22:00', null, null], [$byId[3]['time'], $byId[3]['until'], $byId[3]['location'], $byId[3]['section']]);
        self::assertNull($byId[4]['section']);
        // the instants the shell compares with the device clock stay
        self::assertSame('2027-05-21T11:00:00+02:00', $byId[1]['start']);
        self::assertSame('2027-05-21T12:30:00+02:00', $byId[1]['end']);
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
        $item = self::build('2027-05-21 08:00:00', mine: $mine)['nextUp']['items'][0];

        // no leading zero on the hour, as Times::CLOCK prints it
        self::assertSame(['9:00', 'do 9:45'], [$item['time'], $item['until']]);
    }

    public function testNoProgrammesNoNextUp(): void
    {
        self::assertNull(self::build('2027-05-21 12:00:00')['nextUp']);
    }

    public function testEverythingOverLeavesTheItemsForTheDeviceClock(): void
    {
        $nextUp = self::build('2027-05-22 12:00:00', mine: self::mine())['nextUp'];

        self::assertCount(4, $nextUp['items']);
        self::assertNull($nextUp['index']);
    }
}
