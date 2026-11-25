<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Module\Home\View\NextItem;
use App\Module\Home\View\NextUpView;
use App\Program\Programme;

/**
 * Domů's "Můj program" as the Program screen's Moje list orders it (HomeModel adds what
 * each card says). Pure: the moment comes in as an argument.
 */
final class NextUp
{
    /**
     * The homepage's "next programme" card: the participant's own programmes in order,
     * flattened from Moje's days, and the index of the first one not yet over at
     * `$now` (null when everything is). Every card is rendered: HomeModel marks the ones the
     * server shows, which is what a reader without JS sees, and the device clock picks them
     * again (`www/js/home/nextup.js`), so a copy cached days ago is still right.
     *
     * Each item is a Moje row with its day's label; HomeModel then writes what the card says.
     *
     * @param list<Programme> $mine
     */
    public static function nextUp(array $mine, int $now): NextUpView
    {
        $mine = ScheduleBuilder::withUniqueIds(ScheduleBuilder::withParsableDates($mine));
        $items = [];
        foreach (DayListBuilder::buildDays($mine, array_fill_keys(array_map(static fn(Programme $programme): int => $programme->id, $mine), true), true) as $day) {
            foreach ($day->items as $item) {
                $items[] = new NextItem(
                    id: $item->id,
                    name: $item->name,
                    day: $day->label,
                    time: $item->time,
                    until: $item->until,
                    range: $item->range,
                    start: $item->start,
                    end: $item->end,
                    location: $item->location,
                    section: null,
                    sectionId: 0,
                    perex: $item->perex,
                    registered: $item->registered,
                    dimmed: $item->dimmed,
                    // which cards show, and what they lead with, is HomeModel's call (forCard())
                    shown: false,
                    lead: '',
                );
            }
        }
        $index = null;
        foreach ($items as $i => $item) {
            if (strtotime($item->end) > $now) {
                $index = $i;
                break;
            }
        }

        return new NextUpView($items, $index);
    }
}
