<?php

declare(strict_types=1);

namespace App\Module;

use App\Auth\Authenticator;
use App\Auth\UnknownParticipantException;
use App\EventConfig;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Push\MessageRepository;
use App\Session;
use GuzzleHttp\Exception\TransferException;
use Slim\App;
use Slim\Views\Twig;

/**
 * The single Program screen (/programy).
 *
 * The screen renders two views into one page and switches between them client side:
 * a Gantt-style timeline of everything, and a personal list of the programmes the
 * logged-in participant is registered for. All grouping happens here, in PHP —
 * the template only walks an already-shaped view model.
 */
final class ProgramsModule implements ModuleInterface
{
    /**
     * Czech weekday abbreviations for the pager label, indexed by date('N').
     * Kernel carries the full names for its dateToCzechDayName filter; the pager
     * needs the short forms, which do not exist anywhere else.
     */
    private const array SHORT_DAY_NAMES = [1 => 'po', 2 => 'út', 3 => 'st', 4 => 'čt', 5 => 'pá', 6 => 'so', 7 => 'ne'];

    /** Stage row for programmes that carry no location. */
    private const string NO_LOCATION_LABEL = 'Bez lokace';

    /** Width of one timeline card lane in seconds — the axis is always rounded to whole hours. */
    private const int HOUR = 3600;

    /**
     * Most calendar days one programme is drawn across. Each day's piece is clipped to
     * that day, so no record can stretch a page's axis; this bounds the other way a
     * typo'd `end` from kissj could hurt — a bar on every day of the next ten years.
     * The clip is a drawing decision only: the detail sheet still shows the true end.
     */
    private const int MAX_DRAWN_DAYS = 14;

    /** Shortest bar the grid will draw, in seconds — below this a card is unreadable. */
    private const int MIN_DRAWN_SPAN = 60;

    /**
     * Kernel::TIMEZONE: the zone the provider's naive datetimes are wall-clock times in.
     * Everything else here reads and prints them in the process's default zone, which is
     * consistent whatever that zone is; only an instant handed to the client has to name
     * the event's own.
     */
    private const string ZONE = 'Europe/Prague';

    public static function key(): string
    {
        return 'programs';
    }

    public function menuItem(): ?array
    {
        return ['label' => 'Program', 'route' => 'programs', 'icon' => 'far fa-calendar-alt', 'order' => 10];
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/programy', function ($request, $response) {
            $auth = $this->get(Authenticator::class);
            $provider = $this->get(ProgramProviderInterface::class);
            // Both provider calls can fail in the same request — kissj being down is
            // exactly when they do — and the first branch signs the participant out.
            // One slot would let the second failure replace the explanation for that,
            // so the notices accumulate and the template renders them as one line.
            $notices = [];
            $mine = [];
            // only a list that was actually read can say "not registered" or "nothing yet"
            $mineRead = false;

            if ($auth->isLogged()) {
                try {
                    $mine = $provider->getProgramsForIdentity($auth->identity());
                    $mineRead = true;
                } catch (UnknownParticipantException) {
                    $auth->logout();
                    $notices[] = 'Tvůj TIE kód už neplatí, odhlásili jsme tě.';
                } catch (TransferException | ProgramDataException) {
                    // never arrived, or arrived as something that is not programme data —
                    // the reader is told the same thing either way
                    $notices[] = 'Osobní program se nepodařilo načíst.';
                }
            }

            $all = [];
            $sections = [];
            // no list at all, not even a stale one: the template then shows no freshness line
            $unavailable = false;
            try {
                $all = $provider->getPrograms();
                $sections = $provider->getSections();
            } catch (TransferException | ProgramDataException) {
                $notices[] = 'Programy se nepodařilo načíst, zkus to prosím později.';
                $unavailable = true;
            }

            $model = ProgramsModule::buildViewModel($sections, $all, $mine, $mineRead);

            // the organisers' messages about a programme are in its sheet, for everybody
            $messages = [];
            foreach ($this->get(MessageRepository::class)->visible($this->get(EventConfig::class)->slug) as $message) {
                if ($message['programmeId'] !== null) {
                    $messages[$message['programmeId']][] = [
                        'when' => NewsModule::when($message['sentAt']),
                        'title' => $message['title'],
                        'body' => $message['body'],
                    ];
                }
            }
            foreach ($model['details'] as $i => $detail) {
                $model['details'][$i]['messages'] = $messages[(int) $detail['id']] ?? [];
            }

            // a failed login from Můj program lands back here and is explained in place
            $session = $this->get(Session::class);
            $tieError = $session->get('tieError');
            $session->delete('tieError');

            return $this->get(Twig::class)->render($response, 'programs.twig', $model + [
                'notice' => $notices === [] ? null : implode(' ', $notices),
                'programmesUnavailable' => $unavailable,
                'isLogged' => $auth->isLogged(),
                'mineRead' => $mineRead,
                'identity' => $auth->identity()?->displayName,
                'tieError' => $tieError,
            ]);
        })->setName('programs');
    }

    /**
     * The homepage's "next programme" card: the participant's own programmes in order,
     * flattened from Můj program's days, and the index of the first one not yet over at
     * `$now` (null when everything is). Every card is rendered and the device clock picks
     * the one to show (`www/shell.js`), so a copy cached days ago is still right; the
     * server's own pick is what a reader without JS sees.
     *
     * @param list<array> $mine
     * @return array{items: list<array>, index: ?int}
     */
    public static function nextUp(array $mine, int $now): array
    {
        $items = [];
        foreach (self::buildDays(self::withUniqueIds(self::withParsableDates($mine))) as $day) {
            foreach ($day['items'] as $item) {
                $items[] = $item + ['day' => $day['label']];
            }
        }
        $index = null;
        foreach ($items as $i => $item) {
            if (strtotime($item['end']) > $now) {
                $index = $i;
                break;
            }
        }

        return ['items' => $items, 'index' => $index];
    }

    /**
     * Drops every record the screen cannot place in time. `strtotime` answers false for
     * an empty or missing date, `ts()` would turn that into 1970-01-01, and a single such
     * record puts the whole screen on a page labelled "čt 1. 1." — `activeKey` opens on
     * the first page whenever today is not one of them. It arrives without any malice:
     * KissjProgramProvider maps a missing `start` to an empty string.
     *
     * @param list<array> $programs
     * @return list<array>
     */
    private static function withParsableDates(array $programs): array
    {
        return array_values(array_filter(
            $programs,
            static fn (array $program): bool => self::parse($program['start'] ?? null) !== null
                && self::parse($program['end'] ?? null) !== null,
        ));
    }

    /**
     * Keeps the first record of every id. Two records sharing one id render two cards but
     * a single detail body, so both cards open the second one's sheet; and the morph keys
     * on the id, where a duplicate is re-created instead of matched and loses its live
     * state. The cards, the details and the morph agree only if the id is unique here.
     *
     * @param list<array> $programs
     * @return list<array>
     */
    private static function withUniqueIds(array $programs): array
    {
        $byId = [];
        foreach ($programs as $program) {
            $byId[$program['id']] ??= $program;
        }

        return array_values($byId);
    }

    /**
     * Shapes everything the template needs:
     *  - pages:  one per day that actually has programmes, every section together, each
     *            already carrying its hour ruler and its stage rows with positioned cards
     *  - days:   one per day of the participant's own programme, for the list view
     *  - details: modal payload per programme id, for both views and the hash deep link
     *
     * @param array<int, array> $sections from getSections(), keyed by id in display order
     * @param list<array> $all programmes from getPrograms()
     * @param list<array> $mine programmes from getProgramsForIdentity()
     * @param bool $mineRead whether the personal list was read: only then is "not
     *                       registered" true, and only then is anything dimmed
     */
    private static function buildViewModel(array $sections, array $all, array $mine, bool $mineRead): array
    {
        // everything below reads the dates and the ids as given, so both are made sound
        // once, here, rather than guarded at every use
        $all = self::withUniqueIds(self::withParsableDates($all));
        $mine = self::withUniqueIds(self::withParsableDates($mine));

        $registeredIds = [];
        foreach ($mine as $program) {
            $registeredIds[$program['id']] = true;
        }

        // group everything by day, dropping programmes in a section the provider did not
        // list. A programme running over several days goes on the page of each of them.
        $grouped = [];
        foreach ($all as $program) {
            $sectionId = $program['section']['id'] ?? null;
            if ($sectionId === null || !isset($sections[$sectionId])) {
                continue;
            }
            foreach (self::segments($program) as $segment) {
                $grouped[$segment['day']][] = $segment;
            }
        }
        ksort($grouped);

        $pages = [];
        $pageOfProgram = [];
        foreach ($grouped as $day => $segments) {
            $page = self::buildPage($day, $segments, $registeredIds, $mineRead);
            // the days run in order, so the first page a programme is met on is the
            // one it starts on — the page its sheet and its deep link belong to
            foreach ($segments as $segment) {
                $pageOfProgram[$segment['program']['id']] ??= $page['key'];
            }
            $pages[] = $page;
        }

        $days = self::buildDays($mine);

        $details = [];
        foreach (array_merge($all, $mine) as $program) {
            $details[$program['id']] = self::buildDetail($program, $sections, $pageOfProgram, $registeredIds);
        }

        return [
            'pages' => $pages,
            'activePage' => self::activeKey($pages),
            'days' => $days,
            // The personal list is one continuous scroll over the whole event, so the
            // day its strip opens on is the day at the top of that scroll — the first —
            // and not today. The timeline still opens on today, because it pages.
            'activeDay' => $days[0]['key'] ?? null,
            'details' => array_values($details),
        ];
    }

    /**
     * One timeline page: the hour ruler plus one stage row per location, each row split
     * into as many tracks as it takes for overlapping programmes not to cover each other.
     *
     * @param list<array{program: array, day: string, start: int, end: int}> $segments this day's pieces
     * @param array<int, true> $registeredIds
     */
    private static function buildPage(string $day, array $segments, array $registeredIds, bool $mineRead): array
    {
        // the axis is measured against the ends the bars are actually drawn to, so a
        // programme running on past midnight widens this page no further than the day —
        // and a bar widened to the minimum still ends inside the grid
        $starts = array_column($segments, 'start');
        $ends = array_map(self::barEnd(...), $segments);

        $axisStart = intdiv(min($starts), self::HOUR) * self::HOUR;
        $axisEnd = (int) (ceil(max($ends) / self::HOUR) * self::HOUR);
        if ($axisEnd <= $axisStart) {
            $axisEnd = $axisStart + self::HOUR;
        }

        // the last tick sits on the right edge, where its label would hang outside the grid.
        // Offsets are in hours, not percent: the grid is drawn at a fixed pixel-per-hour
        // scale (--hour-width), so every position is calc(--hour-width * offset).
        $ruler = [];
        for ($tick = $axisStart; $tick < $axisEnd; $tick += self::HOUR) {
            $ruler[] = ['label' => date('H:i', $tick), 'offset' => self::hours($tick - $axisStart)];
        }

        $byLocation = [];
        foreach ($segments as $segment) {
            $location = trim((string) ($segment['program']['location'] ?? ''));
            $byLocation[$location][] = $segment;
        }
        // stages read top to bottom in the order their first programme starts; no location goes last.
        // The earliest start is computed once per location rather than inside the comparator,
        // which walked both groups in full on every one of the O(n log n) comparisons.
        $firstStart = array_map(
            static fn (array $items): int => min(array_column($items, 'start')),
            $byLocation,
        );
        uksort($byLocation, static fn ($a, $b): int => $firstStart[$a] <=> $firstStart[$b]);

        $rows = [];
        foreach ($byLocation as $location => $items) {
            $rows[] = [
                'location' => $location === '' ? self::NO_LOCATION_LABEL : $location,
                'tracks' => self::packTracks($items, $axisStart, $registeredIds, $mineRead),
            ];
        }
        // the unlocated stage sorts by time like the rest, but it belongs at the bottom
        usort($rows, static fn (array $a, array $b): int => ($a['location'] === self::NO_LOCATION_LABEL ? 1 : 0)
            <=> ($b['location'] === self::NO_LOCATION_LABEL ? 1 : 0));

        return [
            'key' => 'page-' . date('Ymd', strtotime($day)),
            'day' => $day,
            'label' => self::dayLabel($day),
            'hours' => count($ruler),
            'ruler' => $ruler,
            // the axis as instants, so the client can place "now" on it whatever
            // time zone the phone is set to
            'axisStart' => self::instant($axisStart),
            'axisEnd' => self::instant($axisEnd),
            'rows' => $rows,
        ];
    }

    /**
     * A wall-clock timestamp (read in the default zone, like every other in here) as the
     * instant it means in the event's zone, DATE_ATOM with its offset.
     */
    private static function instant(int $timestamp): string
    {
        return (new \DateTimeImmutable(date('Y-m-d H:i:s', $timestamp), new \DateTimeZone(self::ZONE)))->format(DATE_ATOM);
    }

    /**
     * Greedy interval packing: a programme goes into the first track whose previous
     * programme has already ended, so nothing is ever drawn on top of anything else.
     *
     * @param list<array{program: array, day: string, start: int, end: int}> $items
     * @param array<int, true> $registeredIds
     * @return list<list<array>>
     */
    private static function packTracks(array $items, int $axisStart, array $registeredIds, bool $mineRead): array
    {
        usort($items, static fn (array $a, array $b): int => [$a['start'], -$a['end']] <=> [$b['start'], -$b['end']]);

        // packing works on the same end the bar is drawn to, clip and minimum width
        // included: freeing a track at the clipped end while buildCard() widens a shorter
        // programme to MIN_DRAWN_SPAN put the next card underneath that widened bar
        $trackEnds = [];
        $tracks = [];
        foreach ($items as $segment) {
            $index = 0;
            while (isset($trackEnds[$index]) && $trackEnds[$index] > $segment['start']) {
                $index++;
            }
            $trackEnds[$index] = self::barEnd($segment);
            $tracks[$index][] = self::buildCard($segment, $axisStart, $registeredIds, $mineRead);
        }
        ksort($tracks);

        return array_values($tracks);
    }

    /**
     * One card: a programme's piece of one day. Every piece of a programme carries the
     * same id, label and state, so each opens the same sheet and says the same thing.
     *
     * @param array{program: array, day: string, start: int, end: int} $segment
     * @param array<int, true> $registeredIds
     */
    private static function buildCard(array $segment, int $axisStart, array $registeredIds, bool $mineRead): array
    {
        $program = $segment['program'];
        $start = $segment['start'];
        $end = self::barEnd($segment);
        $registered = isset($registeredIds[$program['id']]);

        return [
            'id' => $program['id'],
            'name' => $program['name'],
            // the label keeps the true times, like the sheet — only the bar is clipped
            'time' => self::timeRange($program),
            // both in hours from the axis start; the template turns them into pixels
            'offset' => self::hours($start - $axisStart),
            'span' => self::hours($end - $start),
            'registered' => $registered,
            // with the personal list read, the programmes that are not yours step back so yours stand out
            'dimmed' => $mineRead && !$registered,
        ];
    }

    /**
     * The list view groups by day, like the timeline, but does not page: every day
     * is rendered and the reader scrolls through all of them — a day the participant
     * has nothing on simply does not exist here, because this is their programme and
     * not the event's.
     *
     * @param list<array> $mine
     */
    private static function buildDays(array $mine): array
    {
        $byDay = [];
        foreach ($mine as $program) {
            $byDay[self::dayOf($program)][] = $program;
        }
        ksort($byDay);

        $days = [];
        foreach ($byDay as $day => $programs) {
            usort($programs, static fn (array $a, array $b): int => self::ts($a['start']) <=> self::ts($b['start']));
            $days[] = [
                'key' => 'day-' . date('Ymd', strtotime($day)),
                'day' => $day,
                'label' => self::dayLabel($day),
                'items' => array_map(static fn (array $p): array => [
                    'id' => $p['id'],
                    'name' => $p['name'],
                    'time' => self::timeRange($p),
                    'start' => self::instant(self::ts($p['start'])),
                    'end' => self::instant(self::ts($p['end'])),
                    'location' => $p['location'] ?? null,
                    'perex' => $p['perex'] ?? null,
                ], $programs),
            ];
        }

        return $days;
    }

    /**
     * @param array<int, array> $sections
     * @param array<int, string> $pageOfProgram
     * @param array<int, true> $registeredIds
     */
    private static function buildDetail(array $program, array $sections, array $pageOfProgram, array $registeredIds): array
    {
        $section = $sections[$program['section']['id'] ?? -1] ?? null;

        return [
            'id' => $program['id'],
            'name' => $program['name'],
            'when' => self::whenLabel($program),
            'location' => $program['location'] ?? null,
            'perex' => $program['perex'] ?? null,
            'lector' => $program['lector'] ?? null,
            'tools' => $program['tools'] ?? null,
            'section' => $section === null ? null : self::sectionTitle($section),
            'image' => $section['image'] ?? null,
            'attachment' => $section['attachment'] ?? null,
            'page' => $pageOfProgram[$program['id']] ?? null,
            'registered' => isset($registeredIds[$program['id']]),
        ];
    }

    /**
     * The screen opens on today if the event is running, otherwise on its first page.
     *
     * @param list<array{key: string, day: string}> $pages
     */
    private static function activeKey(array $pages): ?string
    {
        $today = date('Y-m-d');
        foreach ($pages as $page) {
            if ($page['day'] === $today) {
                return $page['key'];
            }
        }

        return $pages[0]['key'] ?? null;
    }

    private static function sectionTitle(array $section): string
    {
        return isset($section['subTitle']) ? $section['title'] . ' – ' . $section['subTitle'] : $section['title'];
    }

    /** e.g. "čt 30. 5." */
    private static function dayLabel(string $day): string
    {
        $date = new \DateTimeImmutable($day);

        return self::SHORT_DAY_NAMES[(int) $date->format('N')] . ' ' . $date->format('j. n.');
    }

    /**
     * e.g. "08:00 – 12:00"; a programme that runs into another day names both days, the
     * way the sheet does. Ending at midnight is ending the evening it started on.
     */
    private static function timeRange(array $program): string
    {
        $segments = self::segments($program);
        if (count($segments) > 1) {
            return self::whenLabel($program);
        }

        return date('H:i', self::ts($program['start'])) . ' – ' . date('H:i', self::ts($program['end']));
    }

    /** e.g. "čt 30. 5. 08:00 – 12:00", or with both days when the programme runs past midnight */
    private static function whenLabel(array $program): string
    {
        $start = self::ts($program['start']);
        $end = self::ts($program['end']);
        $startDay = self::dayLabel(date('Y-m-d', $start));
        $endDay = self::dayLabel(date('Y-m-d', $end));

        return $startDay . ' ' . date('H:i', $start) . ' – '
            . ($startDay === $endDay ? '' : $endDay . ' ') . date('H:i', $end);
    }

    private static function dayOf(array $program): string
    {
        return date('Y-m-d', self::ts($program['start']));
    }

    private static function ts(mixed $datetime): int
    {
        return self::parse($datetime) ?? 0;
    }

    /**
     * The timestamp of a `{'date': ...}` value, or null when there is none to read —
     * a null, a missing key, an empty string or anything strtotime() rejects.
     * withParsableDates() is what keeps such a record off the screen; this is also
     * why ts() takes mixed, so a hand-edited fixture is a dropped row and not a
     * TypeError five frames further in.
     */
    private static function parse(mixed $datetime): ?int
    {
        $date = is_array($datetime) ? ($datetime['date'] ?? null) : null;
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * A programme cut into one piece per calendar day it overlaps, each clipped to its
     * day: from the later of its start and the day's midnight to the earlier of its end
     * and the next midnight. The day it starts on always has a piece, even a record of
     * no length; any later day only if the programme is still running into it, so an
     * end at exactly midnight leaves no zero-width card on the next day. Only the bars
     * are cut — buildDetail reads $program['end'] directly, so the sheet keeps the true time.
     *
     * @return list<array{program: array, day: string, start: int, end: int}>
     */
    private static function segments(array $program): array
    {
        $start = self::ts($program['start']);
        $end = max(self::ts($program['end']), $start);
        $day = date('Y-m-d', $start);

        $segments = [];
        do {
            // by the calendar rather than by 86400 seconds, which a DST change would break
            $dayStart = (int) strtotime($day);
            $nextDay = date('Y-m-d', (int) strtotime($day . ' +1 day'));
            $dayEnd = (int) strtotime($nextDay);
            $segments[] = ['program' => $program, 'day' => $day, 'start' => max($start, $dayStart), 'end' => min($end, $dayEnd)];
            $day = $nextDay;
        } while ($end > $dayEnd && count($segments) < self::MAX_DRAWN_DAYS);

        return $segments;
    }

    /**
     * The end of the bar as it is actually drawn: the piece's clipped end, or a minute
     * past its start for a piece too short to see. The packer frees a track at this same
     * value, or a widened bar would be drawn over the card that took the slot after it.
     *
     * @param array{start: int, end: int} $segment
     */
    private static function barEnd(array $segment): int
    {
        return max($segment['end'], $segment['start'] + self::MIN_DRAWN_SPAN);
    }

    /** A duration in hours, the unit the timeline's --hour-width scale is expressed in. */
    private static function hours(int $seconds): float
    {
        return round($seconds / self::HOUR, 4);
    }
}
