<?php

declare(strict_types=1);

namespace App\Module\Programs;

/**
 * Domů's "Můj program" as the Program screen's Moje list orders it (HomeModel adds what
 * each card says). Pure: the moment comes in as an argument.
 */
final class NextUp
{
    /**
     * The homepage's "next programme" card: the participant's own programmes in order,
     * flattened from Moje's days, and the index of the first one not yet over at
     * `$now` (null when everything is). Every card is rendered and the device clock picks
     * the one to show (`www/shell.js`), so a copy cached days ago is still right; the
     * server's own pick is what a reader without JS sees.
     *
     * @param list<array> $mine
     * @return array{items: list<array>, index: ?int}
     */
    public static function nextUp(array $mine, int $now): array
    {
        $mine = ScheduleBuilder::withUniqueIds(ScheduleBuilder::withParsableDates($mine));
        $items = [];
        foreach (DayListBuilder::buildDays($mine, array_fill_keys(array_column($mine, 'id'), true), true) as $day) {
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
}
