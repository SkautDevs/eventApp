<?php

declare(strict_types=1);

namespace App\Module\Admin\View;

/** One programme in the send form's picker: its id and `HH:MM Name – Place`. */
final readonly class ProgrammeOption
{
    public function __construct(
        public int $id,
        public string $label,
    ) {}
}
