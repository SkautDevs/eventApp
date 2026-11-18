<?php

declare(strict_types=1);

namespace App\Program;

/**
 * The event's programme registration window. A window with either end missing is not
 * set, and kissj answers every write with `closed`.
 */
final readonly class RegistrationWindow
{
    public function __construct(
        public ?\DateTimeImmutable $start,
        public ?\DateTimeImmutable $end,
    ) {}

    /**
     * @return 'none'|'before'|'open'|'after' the start belongs to the window, the end does
     *         not; instants compare whatever zone they are written in
     */
    public function state(\DateTimeImmutable $now): string
    {
        if ($this->start === null || $this->end === null) {
            return 'none';
        }
        if ($now < $this->start) {
            return 'before';
        }

        return $now < $this->end ? 'open' : 'after';
    }

    public function isOpen(\DateTimeImmutable $now): bool
    {
        return $this->state($now) === 'open';
    }
}
