<?php

declare(strict_types=1);

namespace App\Module\Home\View;

/**
 * Domů's countdown before the start: whole Prague days to the event's first day, that day
 * (`Y-m-d`, from which www/js/home/countdown.js redraws the days on the device clock) and the event's
 * range as one line (`19.–23. 5. 2027`).
 */
final readonly class Countdown
{
    public function __construct(
        public int $days,
        public string $start,
        public string $range,
    ) {}
}
