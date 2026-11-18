<?php

declare(strict_types=1);

namespace App\Module\Home\View;

/**
 * Domů's "Můj program": every programme of the participant in Moje's order, and the index
 * of the first one not yet over (null when everything is, which hides the box). The device
 * clock picks the cards to show; the server's pick is what a reader without JS sees.
 */
final readonly class NextUpView
{
    /** @param list<NextItem> $items */
    public function __construct(
        public array $items,
        public ?int $index,
    ) {}
}
