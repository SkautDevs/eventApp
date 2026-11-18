<?php

declare(strict_types=1);

namespace App\Module\Admin\View;

/**
 * One day of the send form's picker: its first programme's start (which the template names
 * the day from with the dateToCzechDayName filter), the date as `j. n.`, and the day's
 * programmes in start order.
 */
final readonly class ProgrammeGroup
{
    /** @param list<ProgrammeOption> $options */
    public function __construct(
        public \DateTimeImmutable $start,
        public string $label,
        public array $options,
    ) {}
}
