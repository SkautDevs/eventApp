<?php

declare(strict_types=1);

namespace App\Home;

use App\EventConfig;
use App\Module\ProgramsModule;
use App\Program\RegistrationWindow;
use App\Push\NotificationList;
use App\Time\Zone;

/**
 * Domů's view model. The server decides which half of the screen a reader sees from the
 * event's dates: before the start a countdown and what is coming (the registration
 * window), from the start today's date, today's notifications and the participant's next
 * programmes. Every date here is read on the Prague calendar; the device clock redraws
 * the countdown and the next programmes in www/shell.js.
 */
final class HomeModel
{
    /** Czech weekday names, indexed by format('N'); also the dateToCzechDayName filter's. */
    public const array WEEKDAYS = [
        1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek',
        5 => 'pátek', 6 => 'sobota', 7 => 'neděle',
    ];

    /**
     * @param list<array> $news NotificationList::items() of the messages this reader may see
     * @param list<array> $mine the participant's programmes in the internal shape
     * @param array<int, array{id: int, title: string, subTitle: ?string}> $sections getSections()
     * @return array{
     *     phase: 'before'|'during'|'after'|null,
     *     todayLabel: string,
     *     countdown: array{days: int, start: string, range: string}|null,
     *     window: array{state: 'before'|'open', label: string}|null,
     *     paired: bool,
     *     news: list<array>,
     *     nextUp: array{items: list<array>, index: ?int}|null,
     * }
     */
    public static function build(
        EventConfig $event,
        array $news,
        array $mine,
        array $sections,
        bool $paired,
        RegistrationWindow $window,
        \DateTimeImmutable $now,
    ): array {
        $phase = self::phase($event, $now);

        return [
            'phase' => $phase,
            'todayLabel' => self::todayLabel($now),
            'countdown' => $phase === 'before' && $event->dates !== null
                ? [
                    'days' => self::daysUntil($event->dates['start'], $now),
                    'start' => $event->dates['start'],
                    'range' => self::range($event->dates['start'], $event->dates['end']),
                ]
                : null,
            'window' => self::window($window, $now),
            'paired' => $paired,
            'news' => NotificationList::today($news, $now),
            'nextUp' => self::nextUp($mine, $sections, $now),
        ];
    }

    /**
     * Where the event is on the Prague calendar: before its first day, on one of its days,
     * or after its last; null for an event without dates, which Domů renders as during.
     *
     * @return 'before'|'during'|'after'|null
     */
    public static function phase(EventConfig $event, \DateTimeImmutable $now): ?string
    {
        if ($event->dates === null) {
            return null;
        }
        $today = self::prague($now)->format('Y-m-d');
        if ($today < $event->dates['start']) {
            return 'before';
        }

        return $today <= $event->dates['end'] ? 'during' : 'after';
    }

    /** "Pátek 21. 5." */
    public static function todayLabel(\DateTimeImmutable $now): string
    {
        $day = self::prague($now);
        $name = self::WEEKDAYS[(int) $day->format('N')];

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1) . ' ' . $day->format('j. n.');
    }

    /**
     * The event's dates as one range, each part written once: "19.–23. 5. 2027",
     * "30. 4.–2. 5. 2027", "30. 12. 2027–2. 1. 2028", or "19. 5. 2027" for one day.
     */
    public static function range(string $start, string $end): string
    {
        $from = new \DateTimeImmutable($start);
        $to = new \DateTimeImmutable($end);
        if ($start === $end) {
            return $to->format('j. n. Y');
        }
        if ($from->format('Y') !== $to->format('Y')) {
            return $from->format('j. n. Y') . '–' . $to->format('j. n. Y');
        }
        if ($from->format('n') !== $to->format('n')) {
            return $from->format('j. n.') . '–' . $to->format('j. n. Y');
        }

        return $from->format('j.') . '–' . $to->format('j. n. Y');
    }

    private static function prague(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->setTimezone(Zone::prague());
    }

    /** Whole Prague calendar days from now's date to $date (Y-m-d). */
    private static function daysUntil(string $date, \DateTimeImmutable $now): int
    {
        // both at UTC midnight, so no DST change can make a day 23 or 25 hours long
        $utc = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable(self::prague($now)->format('Y-m-d'), $utc);
        $then = new \DateTimeImmutable($date, $utc);

        return intdiv($then->getTimestamp() - $today->getTimestamp(), 86400);
    }

    /** @return array{state: 'before'|'open', label: string}|null */
    private static function window(RegistrationWindow $window, \DateTimeImmutable $now): ?array
    {
        return match ($window->state($now)) {
            'before' => ['state' => 'before', 'label' => self::prague($window->start)->format('j. n. Y · H:i')],
            'open' => ['state' => 'open', 'label' => 'Do ' . self::prague($window->end)->format('j. n. Y')],
            default => null,
        };
    }

    /**
     * ProgramsModule::nextUp() as the Program screen's list builds it, each item extended
     * with what its card says: the start and end as Prague wall-clock times (written
     * as ProgramsModule::CLOCK, like the Program screen), the place and
     * the title of its section. Null when the participant has no programme to show.
     *
     * @param list<array> $mine
     * @param array<int, array{id: int, title: string, subTitle: ?string}> $sections
     * @return array{items: list<array>, index: ?int}|null
     */
    private static function nextUp(array $mine, array $sections, \DateTimeImmutable $now): ?array
    {
        $nextUp = ProgramsModule::nextUp($mine, $now->getTimestamp());
        if ($nextUp['items'] === []) {
            return null;
        }
        $byId = [];
        foreach ($mine as $programme) {
            $byId[$programme['id']] ??= $programme;
        }
        foreach ($nextUp['items'] as $i => $item) {
            $programme = $byId[$item['id']] ?? [];
            $section = $sections[$programme['section']['id'] ?? -1] ?? null;
            $location = $programme['location'] ?? null;
            $nextUp['items'][$i] = [
                'time' => self::prague(new \DateTimeImmutable($item['start']))->format(ProgramsModule::CLOCK),
                'until' => 'do ' . self::prague(new \DateTimeImmutable($item['end']))->format(ProgramsModule::CLOCK),
                'location' => is_string($location) && $location !== '' ? $location : null,
                'section' => $section['title'] ?? null,
            ] + $item;
        }

        return $nextUp;
    }
}
