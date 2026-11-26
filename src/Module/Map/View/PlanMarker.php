<?php

declare(strict_types=1);

namespace App\Module\Map\View;

/**
 * One place on the plan: its id (the marker's `misto-<id>`), its name (the label), where
 * it sits in % of the image's width and height (`sprintf('%.2F')`), and whether the
 * address names it, the only marker shown.
 */
final readonly class PlanMarker
{
    public function __construct(
        public int $id,
        public string $name,
        public string $left,
        public string $top,
        public bool $shown,
    ) {}
}
