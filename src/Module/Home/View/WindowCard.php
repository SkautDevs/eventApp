<?php

declare(strict_types=1);

namespace App\Module\Home\View;

/**
 * Domů's registration window card: `state` is `before` (the label is the opening date and
 * time) or `open` (the label is `Do <end date>`). A string, because the template compares
 * it with one.
 */
final readonly class WindowCard
{
    public function __construct(
        public string $state,
        public string $label,
    ) {}
}
