<?php

declare(strict_types=1);

namespace App\Module\Home\View;

/**
 * One card of Domů's "Můj program": a Moje list row with the day it is listed under
 * (NextUp::nextUp()), then rewritten by HomeModel with what the card says (forCard()): the
 * start and the end as Prague wall-clock times, the place as given (only an empty one is
 * none), the title of its section and the section's id, which the card's deep link names
 * (0 for none), whether the server shows the card and its lead line. `start` and `end` are
 * DATE_ATOM instants. A Moje row is not shown and has no lead until forCard() says otherwise.
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
        public int $sectionId,
        public ?string $perex,
        public bool $registered,
        public bool $dimmed,
        public bool $shown,
        public string $lead,
    ) {}

    /** A copy with what Domů's card says in place of the list row's. */
    public function forCard(string $time, string $until, ?string $location, ?string $section, int $sectionId, bool $shown, string $lead): self
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
            $sectionId,
            $this->perex,
            $this->registered,
            $this->dimmed,
            $shown,
            $lead,
        );
    }
}
