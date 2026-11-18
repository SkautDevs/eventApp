<?php

declare(strict_types=1);

namespace App\Program;

/**
 * What a screen section carries about the age of its provider data (`data_freshness()`):
 * `fetchedAt`, the oldest fetch served (or the request time), as DATE_ATOM in Prague, and
 * `stale`, whether an old copy was served because kissj failed.
 */
final readonly class FreshnessView
{
    public function __construct(
        public string $fetchedAt,
        public bool $stale,
    ) {}
}
