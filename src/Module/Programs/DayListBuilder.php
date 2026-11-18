<?php

declare(strict_types=1);

namespace App\Module\Programs;

/**
 * Přehled and Moje, the Program screen's two lists: programmes grouped by calendar day,
 * every day rendered one after the other. Pure.
 */
final class DayListBuilder
{
    /**
     * Přehled and Moje group by day, like the timeline, but do not page: every day is
     * rendered and the reader scrolls through all of them — a day with nothing on it
     * simply does not exist in the list. One shape for both; the template decides what
     * each view shows of it (only Přehled dims).
     *
     * @param list<array> $programs
     * @param array<int, true> $registeredIds the participant's programme ids
     * @param bool $mineRead whether the personal list was read: only then is anything dimmed
     */
    public static function buildDays(array $programs, array $registeredIds, bool $mineRead): array
    {
        $byDay = [];
        foreach ($programs as $program) {
            $byDay[Times::dayOf($program)][] = $program;
        }
        ksort($byDay);

        $days = [];
        foreach ($byDay as $day => $dayPrograms) {
            usort($dayPrograms, static fn(array $a, array $b): int => Times::ts($a['start']) <=> Times::ts($b['start']));
            $days[] = [
                'key' => 'day-' . date('Ymd', strtotime($day)),
                'day' => $day,
                'label' => Times::dayLabel($day),
                'items' => array_map(static function (array $p) use ($registeredIds, $mineRead): array {
                    $start = Times::ts($p['start']);
                    $end = Times::ts($p['end']);
                    $registered = isset($registeredIds[$p['id']]);
                    $location = trim((string) ($p['location'] ?? ''));

                    return [
                        'id' => $p['id'],
                        'name' => $p['name'],
                        'time' => date(Times::CLOCK, $start),
                        // a programme running into another day names the day it ends on,
                        // as every other label of a multi-day run does
                        'until' => 'do ' . (count(TimelineBuilder::segments($p)) > 1 ? Times::dayLabel(date('Y-m-d', $end)) . ' ' : '') . date(Times::CLOCK, $end),
                        'range' => date(Times::CLOCK, $start) . '–' . date(Times::CLOCK, $end),
                        'start' => Times::instant($start),
                        'end' => Times::instant($end),
                        'location' => $location === '' ? null : $location,
                        'perex' => $p['perex'] ?? null,
                        'registered' => $registered,
                        // a preregistered programme is never dimmed: see TimelineBuilder::buildCard()
                        'dimmed' => $mineRead && !$registered && !SheetBuilder::preregistered($p),
                    ];
                }, $dayPrograms),
            ];
        }

        return $days;
    }
}
