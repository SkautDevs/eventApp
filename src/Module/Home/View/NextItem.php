<?php

declare(strict_types=1);

namespace App\Module\Home\View;

/**
 * One card of Domů's "Můj program": a Moje list row with the day it is listed under
 * (NextUp::nextUp()), then rewritten by HomeModel with what the card says (forCard()): the
 * start and the end as Prague wall-clock times, the place as given (only an empty one is
 * none) and the title of its section. `start` and `end` are DATE_ATOM instants.
 */
final readonly class NextItem
{
    public function __construct(
        public int $id,
        public string $name,
        public string $day,
        public string $time,
        public string $until,
        public string $range,
        public string $start,
        public string $end,
        public ?string $location,
        public ?string $section,
        public ?string $perex,
        public bool $registered,
        public bool $dimmed,
    ) {}

    /** A copy with what Domů's card says in place of the list row's. */
    public function forCard(string $time, string $until, ?string $location, ?string $section): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->day,
            $time,
            $until,
            $this->range,
            $this->start,
            $this->end,
            $location,
            $section,
            $this->perex,
            $this->registered,
            $this->dimmed,
        );
    }
}
