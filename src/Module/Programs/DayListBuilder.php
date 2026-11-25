<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Module\Programs\View\Day;
use App\Module\Programs\View\ListItem;
use App\Program\Programme;

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
     * @param list<Programme> $programs
     * @param array<int, true> $registeredIds the participant's programme ids
     * @param bool $mineRead whether the personal list was read: only then is anything dimmed
     * @return list<Day>
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
            usort($dayPrograms, static fn(Programme $a, Programme $b): int => Times::start($a)->getTimestamp() <=> Times::start($b)->getTimestamp());
            $days[] = new Day(
                key: 'day-' . (new \DateTimeImmutable($day))->format('Ymd'),
                day: $day,
                label: Times::dayLabel($day),
                items: array_map(static function (Programme $p) use ($registeredIds, $mineRead): ListItem {
                    $start = Times::start($p);
                    $end = Times::end($p);
                    $registered = isset($registeredIds[$p->id]);
                    $location = trim($p->location ?? '');

                    return new ListItem(
                        id: $p->id,
                        // the sheet's hash the row links to, as Sheet::$sectionId has it
                        sectionId: $p->sectionId,
                        name: $p->name,
                        time: $start->format(Times::CLOCK),
                        // a programme running into another day names the day it ends on,
                        // as every other label of a multi-day run does
                        until: 'do ' . (count(TimelineBuilder::segments($p)) > 1 ? Times::dayLabel($end->format('Y-m-d')) . ' ' : '') . $end->format(Times::CLOCK),
                        range: $start->format(Times::CLOCK) . '–' . $end->format(Times::CLOCK),
                        start: Times::instant($start),
                        end: Times::instant($end),
                        location: $location === '' ? null : $location,
                        perex: $p->perex,
                        registered: $registered,
                        // a preregistered programme is never dimmed: see TimelineBuilder::buildCard()
                        dimmed: $mineRead && !$registered && !$p->preregistered,
                    );
                }, $dayPrograms),
            );
        }

        return $days;
    }
}
