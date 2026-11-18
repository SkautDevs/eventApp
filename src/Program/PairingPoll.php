<?php

declare(strict_types=1);

namespace App\Program;

/** One poll's answer; only an approval carries the device session token and its expiry. */
final readonly class PairingPoll
{
    public function __construct(
        public PairingStatus $status,
        public ?string $sessionToken = null,
        public ?\DateTimeImmutable $sessionExpiresAt = null,
    ) {}
}
