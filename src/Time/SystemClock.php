<?php

declare(strict_types=1);

namespace App\Time;

use Psr\Clock\ClockInterface;

/**
 * The real clock: the one place the app reads the current time. The container hands it
 * out as ClockInterface, and every class that needs "now" takes one (defaulting to this),
 * so a test can pin the time with a fixed clock instead of sleeping or guessing.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
