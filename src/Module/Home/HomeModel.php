<?php

declare(strict_types=1);

namespace App\Module\Home;

use App\EventConfig;
use App\Module\Home\View\Countdown;
use App\Module\Home\View\HomeView;
use App\Module\Home\View\NextUpView;
use App\Module\Home\View\WindowCard;
use App\Module\Programs\NextUp;
use App\Module\Programs\Times;
use App\Program\Programme;
use App\Program\RegistrationWindow;
use App\Program\Section;
use App\Push\Notification;
use App\Push\NotificationList;
use App\Time\Zone;

/**
 * Domů's view model. The server decides which half of the screen a reader sees from the
 * event's dates: before the start a countdown and what is coming (the registration
 * window), from the start today's date, today's notifications and the participant's next
 * programmes. Every date here is read on the Prague calendar; the device clock redraws
 * the countdown and the next programmes in www/js/home/.
 */
final class HomeModel
{
    /** Czech weekday names, indexed by format('N'); also the dateToCzechDayName filter's. */
    public const array WEEKDAYS = [
        1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek',
        5 => 'pátek', 6 => 'sobota', 7 => 'neděle',
    ];

    /** How many of "Můj program"'s cards show at once: the running one and the next two (nextup.js's NEXT_SHOWN). */
    public const int NEXT_SHOWN = 3;

    /** Within this many milliseconds of its start a card counts the minutes (nextup.js's HOUR). */
    private const int HOUR_MS = 3_600_000;

    /**
     * @param list<Notification> $news NotificationList::items() of the messages this reader may see
     * @param list<Programme> $mine the participant's programmes
     * @param array<int, Section> $sections getSections()
     */
    public static function build(
        EventConfig $event,
        array $news,
        array $mine,
        array $sections,
        bool $paired,
        RegistrationWindow $window,
        \DateTimeImmutable $now,
    ): HomeView {
        $phase = self::phase($event, $now);

        return new HomeView(
            phase: $phase,
            todayLabel: self::todayLabel($now),
            countdown: $phase === 'before' && $event->dates !== null
                ? new Countdown(
                    days: self::daysUntil($event->dates->start, $now),
                    start: $event->dates->start,
                    range: self::range($event->dates->start, $event->dates->end),
                )
                : null,
            window: self::window($window, $now),
            paired: $paired,
            news: NotificationList::today($news, $now),
            nextUp: self::nextUp($mine, $sections, $now),
        );
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
        if ($today < $event->dates->start) {
            return 'before';
        }

        return $today <= $event->dates->end ? 'during' : 'after';
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

    /**
     * A card's lead line at $now, word for word as nextLead() in www/js/home/nextup.js writes
     * it, so the server's copy and the device clock's agree: a started programme
     * "Právě · do 12:30", one starting within the hour (exactly an hour included)
     * "Za 25 min · 14:00", the minutes rounded up, else "14:00"; each followed by
     * " · <place>" when there is one (an empty place is none). Compared to the
     * millisecond, as Date.parse() does.
     */
    public static function lead(\DateTimeImmutable $start, string $time, string $until, ?string $location, \DateTimeImmutable $now): string
    {
        $place = $location !== null && $location !== '' ? ' · ' . $location : '';
        $ahead = (int) $start->format('Uv') - (int) $now->format('Uv');
        if ($ahead <= 0) {
            return 'Právě · ' . $until . $place;
        }
        if ($ahead <= self::HOUR_MS) {
            return 'Za ' . (int) ceil($ahead / 60_000) . ' min · ' . $time . $place;
        }

        return $time . $place;
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

    private static function window(RegistrationWindow $window, \DateTimeImmutable $now): ?WindowCard
    {
        $start = $window->start;
        $end = $window->end;
        // a window with either end missing is 'none', which shows no card
        if ($start === null || $end === null) {
            return null;
        }

        return match ($window->state($now)) {
            'before' => new WindowCard('before', self::prague($start)->format('j. n. Y · H:i')),
            'open' => new WindowCard('open', 'Do ' . self::prague($end)->format('j. n. Y')),
            default => null,
        };
    }

    /**
     * NextUp::nextUp() as the Program screen's list builds it, each item extended
     * with what its card says: the start and end as Prague wall-clock times (written
     * as Times::CLOCK, like the Program screen), the place,
     * the title of its section and the section's id for the card's deep link. The first
     * NEXT_SHOWN items not over at $now are shown, each with its lead(), as shownCards()
     * in www/js/home/nextup.js picks them; the rest stay hidden with no lead, for the device
     * clock to show. Null when the participant has no programme to show.
     *
     * @param list<Programme> $mine
     * @param array<int, Section> $sections
     */
    private static function nextUp(array $mine, array $sections, \DateTimeImmutable $now): ?NextUpView
    {
        $nextUp = NextUp::nextUp($mine, $now->getTimestamp());
        if ($nextUp->items === []) {
            return null;
        }
        $byId = [];
        foreach ($mine as $programme) {
            $byId[$programme->id] ??= $programme;
        }
        $items = [];
        $shownCount = 0;
        foreach ($nextUp->items as $item) {
            // every item is one of $mine
            $programme = $byId[$item->id];
            $section = $sections[$programme->sectionId] ?? null;
            // as given, untrimmed: only an empty place is none
            $location = $programme->location !== null && $programme->location !== '' ? $programme->location : null;
            $start = new \DateTimeImmutable($item->start);
            $end = new \DateTimeImmutable($item->end);
            $time = self::prague($start)->format(Times::CLOCK);
            $until = 'do ' . self::prague($end)->format(Times::CLOCK);
            $shown = $shownCount < self::NEXT_SHOWN && $end > $now;
            if ($shown) {
                $shownCount++;
            }
            $items[] = $item->forCard(
                time: $time,
                until: $until,
                location: $location,
                section: $section?->title,
                sectionId: $programme->sectionId,
                shown: $shown,
                lead: $shown ? self::lead($start, $time, $until, $location, $now) : '',
            );
        }

        return new NextUpView($items, $nextUp->index);
    }
}
