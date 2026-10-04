<?php

declare(strict_types=1);

namespace App\Cache;

/** One cached answer and when it was fetched. */
final readonly class CacheEntry
{
    public function __construct(
        public mixed $data,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }

    /** Seconds since the fetch; negative when the clock has gone back since. */
    public function ageAt(\DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - $this->fetchedAt->getTimestamp();
    }

    /**
     * Fresh means "serve it without asking". A TTL of 0 is never fresh — every read asks
     * again, the entry only covers an outage — and neither is an entry dated in the future,
     * whose real age is unknown.
     */
    public function isFresh(int $ttl, \DateTimeImmutable $now): bool
    {
        $age = $this->ageAt($now);

        return $ttl > 0 && $age >= 0 && $age <= $ttl;
    }
}
