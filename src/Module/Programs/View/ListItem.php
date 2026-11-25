<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

/**
 * One row of Přehled or Moje (DayListBuilder): the programme and its section (the sheet's
 * hash, `#section-<sectionId>-program-<id>`, which the row links to), the start time, the
 * `do …` line (naming the end day of a run into another day), the range, the start and the
 * end as DATE_ATOM instants, the trimmed place (null when empty), the perex, and whether
 * the programme is the participant's and whether it steps back (only when the personal
 * list was read).
 */
final readonly class ListItem
{
    public function __construct(
        public int $id,
        public int $sectionId,
        public string $name,
        public string $time,
        public string $until,
        public string $range,
        public string $start,
        public string $end,
        public ?string $location,
        public ?string $perex,
        public bool $registered,
        public bool $dimmed,
    ) {}
}
