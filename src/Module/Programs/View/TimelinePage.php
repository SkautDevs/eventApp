<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

/**
 * One Harmonogram page, one calendar day (TimelineBuilder::buildPage()): its key
 * (`page-YYYYMMDD`), the day (`Y-m-d`) and its chip label, the number of hours on its
 * axis, the hour ruler, the axis as two DATE_ATOM instants (so the client can place now
 * whatever zone the phone is in) and one stage row per location.
 */
final readonly class TimelinePage
{
    /**
     * @param list<RulerTick> $ruler
     * @param list<StageRow> $rows
     */
    public function __construct(
        public string $key,
        public string $day,
        public string $label,
        public int $hours,
        public array $ruler,
        public string $axisStart,
        public string $axisEnd,
        public array $rows,
    ) {}
}
