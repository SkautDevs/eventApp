<?php

declare(strict_types=1);

namespace App\Module\Programs;

/**
 * The Program screen's whole view model: the provider's arrays made sound (dates that
 * parse, unique ids), then the timeline's pages (TimelineBuilder), Přehled's and Moje's
 * days (DayListBuilder) and every programme's sheet (SheetBuilder), with the page and the
 * days each view opens on. Pure: the request's now comes in as an argument.
 */
final class ScheduleBuilder
{
    /**
     * Shapes everything the template needs:
     *  - pages:  one per day that actually has programmes, every section together, each
     *            already carrying its hour ruler and its stage rows with positioned cards
     *  - overview: one per day of every programme in a listed section, for Přehled
     *  - days:   one per day of the participant's own programme, for Moje
     *  - details: modal payload per programme id, for every view and the hash deep link
     *
     * @param array<int, array> $sections from getSections(), keyed by id in display order
     * @param list<array> $all programmes from getPrograms()
     * @param list<array> $mine programmes from getProgramsForIdentity()
     * @param bool $mineRead whether the personal list was read: only then is "not
     *                       registered" true, and only then is anything dimmed
     * @param \DateTimeImmutable $now the request's now, whose date picks the page the timeline opens on
     */
    public static function buildViewModel(array $sections, array $all, array $mine, bool $mineRead, \DateTimeImmutable $now): array
    {
        // everything below reads the dates and the ids as given, so both are made sound
        // once, here, rather than guarded at every use
        $all = self::withUniqueIds(self::withParsableDates($all));
        $mine = self::withUniqueIds(self::withParsableDates($mine));

        $registeredIds = [];
        foreach ($mine as $program) {
            $registeredIds[$program['id']] = true;
        }

        // programmes in a section the provider did not list are not shown
        $listed = array_values(array_filter(
            $all,
            static fn(array $program): bool => isset($sections[$program['section']['id'] ?? -1]),
        ));

        // group everything by day. A programme running over several days goes on the
        // page of each of them.
        $grouped = [];
        foreach ($listed as $program) {
            foreach (TimelineBuilder::segments($program) as $segment) {
                $grouped[$segment['day']][] = $segment;
            }
        }
        ksort($grouped);

        $pages = [];
        $pageOfProgram = [];
        foreach ($grouped as $day => $segments) {
            $page = TimelineBuilder::buildPage($day, $segments, $registeredIds, $mineRead);
            // the days run in order, so the first page a programme is met on is the
            // one it starts on — the page its sheet and its deep link belong to
            foreach ($segments as $segment) {
                $pageOfProgram[$segment['program']['id']] ??= $page['key'];
            }
            $pages[] = $page;
        }

        $overview = DayListBuilder::buildDays($listed, $registeredIds, $mineRead);
        $days = DayListBuilder::buildDays($mine, $registeredIds, $mineRead);

        $details = [];
        foreach (array_merge($all, $mine) as $program) {
            $details[$program['id']] = SheetBuilder::buildDetail($program, $sections, $pageOfProgram, $registeredIds);
        }

        return [
            'pages' => $pages,
            'activePage' => self::activeKey($pages, $now),
            // Přehled and Moje are continuous scrolls, so each strip opens on the day at the top
            'overview' => $overview,
            'activeOverview' => $overview[0]['key'] ?? null,
            'days' => $days,
            // The personal list is one continuous scroll over the whole event, so the
            // day its strip opens on is the day at the top of that scroll — the first —
            // and not today. The timeline still opens on today, because it pages.
            'activeDay' => $days[0]['key'] ?? null,
            'details' => array_values($details),
        ];
    }

    /**
     * Drops every record the screen cannot place in time. `strtotime` answers false for
     * an empty or missing date, `Times::ts()` would turn that into 1970-01-01, and a single such
     * record puts the whole screen on a page labelled "čt 1. 1." — `activeKey` opens on
     * the first page whenever today is not one of them. It arrives without any malice:
     * KissjProgramProvider maps a missing `start` to an empty string.
     *
     * @param list<array> $programs
     * @return list<array>
     */
    public static function withParsableDates(array $programs): array
    {
        return array_values(array_filter(
            $programs,
            static fn(array $program): bool => Times::parse($program['start'] ?? null) !== null
                && Times::parse($program['end'] ?? null) !== null,
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
    public static function withUniqueIds(array $programs): array
    {
        $byId = [];
        foreach ($programs as $program) {
            $byId[$program['id']] ??= $program;
        }

        return array_values($byId);
    }

    /**
     * The screen opens on today if the event is running, otherwise on its first page.
     *
     * @param list<array{key: string, day: string}> $pages
     * @param \DateTimeImmutable $now its date (in its own zone) is today
     */
    private static function activeKey(array $pages, \DateTimeImmutable $now): ?string
    {
        $today = $now->format('Y-m-d');
        foreach ($pages as $page) {
            if ($page['day'] === $today) {
                return $page['key'];
            }
        }

        return $pages[0]['key'] ?? null;
    }
}
