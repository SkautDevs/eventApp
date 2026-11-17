<?php

declare(strict_types=1);

namespace App\Program;

/** The participant a device session is bound to, with their active registrations. */
final readonly class Participant
{
    /**
     * @param int $id kissj's participant id
     * @param string|null $nickname null when kissj has none
     * @param list<array> $programmes in the internal shape
     */
    public function __construct(
        public int $id,
        public ?string $nickname,
        public array $programmes,
    ) {
    }
}
