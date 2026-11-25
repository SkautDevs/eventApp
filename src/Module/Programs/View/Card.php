<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

/**
 * One timeline card: a programme's piece of one day (TimelineBuilder). Every piece of a
 * programme carries the same id, label (`time`, the true times, not the clipped ones) and
 * state, and links to the one sheet, `#section-<sectionId>-program-<id>`. `offset` and
 * `span` are hours from the axis start, floats printed as PHP prints them; `dimmed` is set
 * only when the personal list was read, and never on a preregistered programme.
 */
final readonly class Card
{
    public function __construct(
        public int $id,
        public int $sectionId,
        public string $name,
        public string $time,
        public float $offset,
        public float $span,
        public bool $registered,
        public bool $dimmed,
    ) {}
}
