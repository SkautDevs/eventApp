<?php

declare(strict_types=1);

namespace Tests;

use Psr\Clock\ClockInterface;

/**
 * A clock that says what the test told it: the instant it was built with until set()
 * moves it.
 */
final class FixedClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now) {}

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
