<?php

declare(strict_types=1);

namespace App\Event;

/**
 * An event's `dates`: its first and last day as `Y-m-d` strings, both inclusive. They stay
 * strings because they are compared and printed as strings (the picker's order and split,
 * Domů's phases). EventConfig::load() has checked both and their order.
 */
final readonly class EventDates
{
    public function __construct(
        public string $start,
        public string $end,
    ) {}
}
