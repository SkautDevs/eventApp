<?php

declare(strict_types=1);

namespace App\Module;

use App\Auth\Authenticator;
use App\Auth\UnknownParticipantException;
use App\EventConfig;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
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
    private const SHORT_DAY_NAMES = [1 => 'po', 2 => 'út', 3 => 'st', 4 => 'čt', 5 => 'pá', 6 => 'so', 7 => 'ne'];

    /** Stage row for programmes that carry no location. */
    private const NO_LOCATION_LABEL = 'Bez lokace';

    /** Width of one timeline card lane in seconds — the axis is always rounded to whole hours. */
    private const HOUR = 3600;

    /**
     * Longest bar the grid will draw, in seconds. A programme that claims to run
     * longer — a typo'd `end` from kissj, say — is drawn clipped at a day rather
     * than stretching the whole page's axis to fit it. The clip is a drawing
     * decision only: the detail sheet still shows the true start and end.
     */
    private const MAX_DRAWN_SPAN = 86400;

    /** Shortest bar the grid will draw, in seconds — below this a card is unreadable. */
    private const MIN_DRAWN_SPAN = 60;

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
            $event = $this->get(EventConfig::class);
            $auth = $this->get(Authenticator::class);
            $provider = $this->get(ProgramProviderInterface::class);
            $hidden = $event->get('programs')['hiddenNames'] ?? [];
            // Both provider calls can fail in the same request — kissj being down is
            // exactly when they do — and the first branch signs the participant out.
            // One slot would let the second failure replace the explanation for that,
            // so the notices accumulate and the template renders them as one line.
            $notices = [];
            $mine = [];

            if ($auth->isLogged()) {
                try {
                    $mine = ProgramsModule::withoutHidden($provider->getProgramsForIdentity($auth->identity()), $hidden);
                } catch (UnknownParticipantException) {
                    $auth->logout();
                    $notices[] = 'Váš TIE kód už není platný, byli jste odhlášeni.';
                } catch (TransferException | ProgramDataException) {
                    // never arrived, or arrived as something that is not programme data —
                    // the reader is told the same thing either way
                    $notices[] = 'Osobní program se nepodařilo načíst.';
                }
            }

            $all = [];
            try {
                $all = ProgramsModule::withoutHidden($provider->getPrograms(), $hidden);
            } catch (TransferException | ProgramDataException) {
                $notices[] = 'Programy se nepodařilo načíst, zkuste to prosím později.';
            }

            $model = ProgramsModule::buildViewModel($event, $all, $mine, $auth->isLogged());

            return $this->get(Twig::class)->render($response, 'programs.twig', $model + [
                'notice' => $notices === [] ? null : implode(' ', $notices),
                'isLogged' => $auth->isLogged(),
                'identity' => $auth->identity()?->displayName,
            ]);
        })->setName('programs');
    }

    /**
     * @param list<array> $programs
     * @param list<string> $hidden
     * @return list<array>
     */
    private static function withoutHidden(array $programs, array $hidden): array
    {
        return array_values(array_filter(
            $programs,
            static fn (array $program): bool => !in_array($program['name'], $hidden, true),
        ));
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
     *  - pages:  one per (day, section) pair that actually has programmes, each already
     *            carrying its hour ruler and its stage rows with positioned cards
     *  - days:   one per day of the participant's own programme, for the list view
     *  - details: modal payload per programme id, for both views and the hash deep link
     *
     * @param list<array> $all programmes from getPrograms()
     * @param list<array> $mine programmes from getProgramsForIdentity()
     */
    private static function buildViewModel(EventConfig $event, array $all, array $mine, bool $isLogged): array
    {
        $sections = $event->sections;
        // everything below reads the dates and the ids as given, so both are made sound
        // once, here, rather than guarded at every use
        $all = self::withUniqueIds(self::withParsableDates($all));
        $mine = self::withUniqueIds(self::withParsableDates($mine));

        $registeredIds = [];
        foreach ($mine as $program) {
            $registeredIds[$program['id']] = true;
        }

        // group everything by day and then by section, dropping sections this event does not know
        $grouped = [];
        foreach ($all as $program) {
            $sectionId = $program['section']['id'] ?? null;
            if ($sectionId === null || !isset($sections[$sectionId])) {
                continue;
            }
            $grouped[self::dayOf($program)][$sectionId][] = $program;
        }
        ksort($grouped);

        $pages = [];
        $pageOfProgram = [];
        foreach ($grouped as $day => $bySection) {
            // sections keep the order the event config lists them in, not the order they arrive in
            foreach ($sections as $sectionId => $section) {
                if (empty($bySection[$sectionId])) {
                    continue;
                }
                $page = self::buildPage($day, $section, $bySection[$sectionId], $registeredIds, $isLogged);
                foreach ($bySection[$sectionId] as $program) {
                    $pageOfProgram[$program['id']] = $page['key'];
                }
                $pages[] = $page;
            }
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
     * @param list<array> $programs
     * @param array<int, true> $registeredIds
     */
    private static function buildPage(string $day, array $section, array $programs, array $registeredIds, bool $isLogged): array
    {
        // the axis is measured against the ends the bars are actually drawn to, so one
        // over-long record cannot widen the page for everything else on it — and a bar
        // widened to the minimum still ends inside the grid
        $starts = array_map(static fn (array $p): int => self::ts($p['start']), $programs);
        $ends = array_map(static fn (array $p): int => self::barEnd($p), $programs);

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
        foreach ($programs as $program) {
            $location = trim((string) ($program['location'] ?? ''));
            $byLocation[$location][] = $program;
        }
        // stages read top to bottom in the order their first programme starts; no location goes last.
        // The earliest start is computed once per location rather than inside the comparator,
        // which walked both groups in full on every one of the O(n log n) comparisons.
        $firstStart = array_map(
            static fn (array $items): int => min(array_map(static fn (array $p): int => self::ts($p['start']), $items)),
            $byLocation,
        );
        uksort($byLocation, static fn ($a, $b): int => $firstStart[$a] <=> $firstStart[$b]);

        $rows = [];
        foreach ($byLocation as $location => $items) {
            $rows[] = [
                'location' => $location === '' ? self::NO_LOCATION_LABEL : $location,
                'tracks' => self::packTracks($items, $axisStart, $registeredIds, $isLogged),
            ];
        }
        // the unlocated stage sorts by time like the rest, but it belongs at the bottom
        usort($rows, static fn (array $a, array $b): int => ($a['location'] === self::NO_LOCATION_LABEL ? 1 : 0)
            <=> ($b['location'] === self::NO_LOCATION_LABEL ? 1 : 0));

        return [
            'key' => 'page-' . date('Ymd', strtotime($day)) . '-' . $section['id'],
            'day' => $day,
            'label' => self::dayLabel($day) . ' ' . self::sectionTitle($section),
            'hours' => count($ruler),
            'ruler' => $ruler,
            'rows' => $rows,
        ];
    }

    /**
     * Greedy interval packing: a programme goes into the first track whose previous
     * programme has already ended, so nothing is ever drawn on top of anything else.
     *
     * @param list<array> $items
     * @param array<int, true> $registeredIds
     * @return list<list<array>>
     */
    private static function packTracks(array $items, int $axisStart, array $registeredIds, bool $isLogged): array
    {
        usort($items, static fn (array $a, array $b): int => [self::ts($a['start']), -self::drawnEnd($a)]
            <=> [self::ts($b['start']), -self::drawnEnd($b)]);

        // packing works on the same end the bar is drawn to, clip and minimum width
        // included: freeing a track at drawnEnd() while buildCard() widens a shorter
        // programme to MIN_DRAWN_SPAN put the next card underneath that widened bar
        $trackEnds = [];
        $tracks = [];
        foreach ($items as $program) {
            $start = self::ts($program['start']);
            $index = 0;
            while (isset($trackEnds[$index]) && $trackEnds[$index] > $start) {
                $index++;
            }
            $trackEnds[$index] = self::barEnd($program);
            $tracks[$index][] = self::buildCard($program, $axisStart, $registeredIds, $isLogged);
        }
        ksort($tracks);

        return array_values($tracks);
    }

    /** @param array<int, true> $registeredIds */
    private static function buildCard(array $program, int $axisStart, array $registeredIds, bool $isLogged): array
    {
        $start = self::ts($program['start']);
        $end = self::barEnd($program);
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
            // logged in, the programmes that are not yours step back so yours stand out
            'dimmed' => $isLogged && !$registered,
        ];
    }

    /**
     * The list view groups by day only: it holds five or so programmes in total, so
     * splitting it by section as well would leave most of its groups empty. Every day
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

    /** e.g. "08:00 – 12:00" */
    private static function timeRange(array $program): string
    {
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
     * The end the grid draws to: the real one, or a day after the start if the
     * record claims longer. Only the bar is affected — buildDetail reads
     * $program['end'] directly, so the sheet keeps the true time.
     */
    private static function drawnEnd(array $program): int
    {
        $start = self::ts($program['start']);

        return min(self::ts($program['end']), $start + self::MAX_DRAWN_SPAN);
    }

    /**
     * The end of the bar as it is actually drawn: the clipped end, or a minute past the
     * start for a programme too short to see. The packer frees a track at this same
     * value, or a widened bar would be drawn over the card that took the slot after it.
     */
    private static function barEnd(array $program): int
    {
        return max(self::drawnEnd($program), self::ts($program['start']) + self::MIN_DRAWN_SPAN);
    }

    /** A duration in hours, the unit the timeline's --hour-width scale is expressed in. */
    private static function hours(int $seconds): float
    {
        return round($seconds / self::HOUR, 4);
    }
}
