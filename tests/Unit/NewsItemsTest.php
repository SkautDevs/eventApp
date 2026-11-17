<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\NewsItems;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NewsItemsTest extends TestCase
{
    private static function prague(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Prague'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function bands(): iterable
    {
        yield 'just now' => ['2027-05-20 10:00:00', '2027-05-20 10:00:59', 'právě teď'];
        yield 'a clock a little ahead' => ['2027-05-20 10:00:30', '2027-05-20 10:00:00', 'právě teď'];
        yield 'one minute' => ['2027-05-20 10:00:00', '2027-05-20 10:01:00', 'před 1 min'];
        yield 'minutes' => ['2027-05-20 10:00:00', '2027-05-20 10:59:59', 'před 59 min'];
        // minutes win over the calendar
        yield 'minutes across midnight' => ['2027-05-20 23:50:00', '2027-05-21 00:10:00', 'před 20 min'];
        yield 'hours the same day' => ['2027-05-20 08:00:00', '2027-05-20 11:30:00', 'před 3 h'];
        yield 'one hour' => ['2027-05-20 08:00:00', '2027-05-20 09:00:00', 'před 1 h'];
        yield 'yesterday, not hours ago' => ['2027-05-20 22:00:00', '2027-05-21 00:10:00', 'včera 22:00'];
        yield 'yesterday morning' => ['2027-05-20 07:05:00', '2027-05-21 23:00:00', 'včera 07:05'];
        yield 'older as a date' => ['2027-05-19 21:40:00', '2027-05-21 08:00:00', '19. 5. 21:40'];
    }

    #[DataProvider('bands')]
    public function testRelative(string $at, string $now, string $expected): void
    {
        self::assertSame($expected, NewsItems::relative(self::prague($at), self::prague($now)));
    }

    /** The bands are Prague's, whatever zone the two instants arrive in. */
    public function testRelativeReadsTheCalendarInPrague(): void
    {
        // 22:00 and 00:10 in Prague (CEST, UTC+2), handed over in UTC
        $at = new \DateTimeImmutable('2027-05-20 20:00:00', new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('2027-05-20 22:10:00', new \DateTimeZone('UTC'));

        self::assertSame('včera 22:00', NewsItems::relative($at, $now));
    }

    /** @return list<array> MessageRepository::visible() rows, newest first */
    private static function messages(): array
    {
        return [
            ['id' => 4, 'sentAt' => '2027-05-21 00:05:00', 'programmeId' => null, 'targetLabel' => 'Všem', 'title' => 'Po půlnoci', 'body' => 'd'],
            ['id' => 3, 'sentAt' => '2027-05-20 23:50:00', 'programmeId' => 7, 'targetLabel' => 'Raft', 'title' => 'Pro raft', 'body' => 'c'],
            ['id' => 2, 'sentAt' => '2027-05-20 08:00:00', 'programmeId' => 9, 'targetLabel' => 'Cizí', 'title' => 'Cizí', 'body' => 'b'],
            ['id' => 1, 'sentAt' => '2027-05-19 12:00:00', 'programmeId' => null, 'targetLabel' => 'Všem', 'title' => 'První', 'body' => 'a'],
        ];
    }

    public function testItemsKeepEverybodysMessagesAndTheReadersProgrammes(): void
    {
        $items = NewsItems::items(self::messages(), [7 => 'Raft na Labi'], self::prague('2027-05-21 00:10:00'));

        self::assertSame([4, 3, 1], array_column($items, 'id'));
        self::assertSame('před 5 min', $items[0]['when']);
        self::assertSame('před 20 min', $items[1]['when']);
        self::assertSame('20. 5.', $items[1]['sentAt']->format('j. n.'));
        // the programme's current name, not the label it was sent under
        self::assertSame('Raft na Labi', $items[1]['programme']);
        self::assertNull($items[0]['programme']);
        self::assertSame('Po půlnoci', $items[0]['title']);
        self::assertSame('d', $items[0]['body']);
    }

    public function testAProgrammeWithoutANameFallsBackToItsTargetLabel(): void
    {
        $items = NewsItems::items(self::messages(), [7 => ''], self::prague('2027-05-21 00:10:00'));

        self::assertSame('Raft', $items[1]['programme']);
    }

    /** The 23:50 message is "před 20 min" at 00:10, and still in its own day's group. */
    public function testGroupsByThePragueDayTheMessageWasSent(): void
    {
        $groups = NewsItems::groups(NewsItems::items(self::messages(), [7 => 'Raft', 9 => 'Cizí'], self::prague('2027-05-21 00:10:00')));

        self::assertSame(['news-20270521', 'news-20270520', 'news-20270519'], array_column($groups, 'key'));
        self::assertSame(['Pá 21. 5.', 'Čt 20. 5.', 'St 19. 5.'], array_column($groups, 'label'));
        self::assertSame([4], array_column($groups[0]['items'], 'id'));
        self::assertSame([3, 2], array_column($groups[1]['items'], 'id'));
        self::assertSame([1], array_column($groups[2]['items'], 'id'));
    }

    public function testNoItemsNoGroups(): void
    {
        self::assertSame([], NewsItems::groups([]));
    }

    public function testTodayIsThePragueCalendarDayOfNow(): void
    {
        $items = NewsItems::items(self::messages(), [7 => 'Raft', 9 => 'Cizí'], self::prague('2027-05-21 00:10:00'));

        self::assertSame([4], array_column(NewsItems::today($items, self::prague('2027-05-21 00:10:00')), 'id'));
        self::assertSame([3, 2], array_column(NewsItems::today($items, self::prague('2027-05-20 23:59:00')), 'id'));
        self::assertSame([], NewsItems::today($items, self::prague('2027-05-22 09:00:00')));
    }
}
