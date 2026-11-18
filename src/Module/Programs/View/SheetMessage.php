<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

/**
 * One organisers' message in a programme's sheet: its relative time (or the stored stamp
 * when that does not parse), the instant www/js/ui/reltime.js redraws it from (null then), the
 * title and the body.
 */
final readonly class SheetMessage
{
    public function __construct(
        public string $when,
        public ?string $at,
        public string $title,
        public string $body,
    ) {}
}
