<?php

declare(strict_types=1);

namespace App\Program;

/**
 * How old the provider data on this screen is. CachingProgramProvider notes every read;
 * the layout prints the result as data-fetched-at / data-stale on the screen section.
 * Request-scoped: the screen middleware resets it, because in a long-lived test app the
 * container — and this one instance with it — outlives a request.
 */
final class Freshness
{
    /** Kernel::TIMEZONE: the attribute is a local time like every other the app prints */
    private const ZONE = 'Europe/Prague';

    private ?\DateTimeImmutable $fetchedAt = null;

    private bool $stale = false;

    private int $generation = 0;

    private \DateTimeImmutable $requestTime;

    public function __construct(?\DateTimeImmutable $requestTime = null)
    {
        $this->requestTime = $requestTime ?? new \DateTimeImmutable();
    }

    public function reset(\DateTimeImmutable $requestTime): void
    {
        $this->requestTime = $requestTime;
        $this->fetchedAt = null;
        $this->stale = false;
        $this->generation++;
    }

    /** One read: the oldest fetch wins, and one stale read makes the screen stale. */
    public function note(\DateTimeImmutable $fetchedAt, bool $stale): void
    {
        if ($this->fetchedAt === null || $fetchedAt < $this->fetchedAt) {
            $this->fetchedAt = $fetchedAt;
        }
        $this->stale = $this->stale || $stale;
    }

    public function fetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function isStale(): bool
    {
        return $this->stale;
    }

    /** Changes on every reset(), so a per-request memo can tell a new request from its own. */
    public function generation(): int
    {
        return $this->generation;
    }

    /** @return array{fetchedAt: string, stale: bool} what the screen section carries */
    public function forView(): array
    {
        return [
            'fetchedAt' => ($this->fetchedAt ?? $this->requestTime)
                ->setTimezone(new \DateTimeZone(self::ZONE))
                ->format(\DATE_ATOM),
            'stale' => $this->stale,
        ];
    }
}
