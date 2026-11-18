<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

/**
 * One stage row of a timeline page: a location (`Bez lokace` for programmes without one)
 * and its tracks, each a list of cards that never overlap.
 */
final readonly class StageRow
{
    /** @param list<list<Card>> $tracks */
    public function __construct(
        public string $location,
        public array $tracks,
    ) {}
}
