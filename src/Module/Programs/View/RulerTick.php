<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

/**
 * One hour mark on a timeline page's ruler: its label (`08:00`) and its position in hours
 * from the axis start, which the template multiplies by `--hour-width`. The offset is a
 * float printed as PHP prints it (`2.0` as `2`).
 */
final readonly class RulerTick
{
    public function __construct(
        public string $label,
        public float $offset,
    ) {}
}
