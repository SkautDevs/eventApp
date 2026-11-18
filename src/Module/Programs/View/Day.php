<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

/**
 * One day of Přehled or Moje (DayListBuilder::buildDays()): its key (`day-YYYYMMDD`), the
 * day (`Y-m-d`), its heading and chip label (`čt 3. 6.`) and its rows in start order.
 */
final readonly class Day
{
    /** @param list<ListItem> $items */
    public function __construct(
        public string $key,
        public string $day,
        public string $label,
        public array $items,
    ) {}
}
