<?php

declare(strict_types=1);

namespace App\Program;

/**
 * A started device pairing (docs/kissj-contract.md, "Device pairing"): the device code is
 * eventApp's to poll with and is shown to nobody; the user code is what the reader types
 * into kissj.
 */
final readonly class Pairing
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $verificationUrl,
        public \DateTimeImmutable $expiresAt,
        public int $interval,
    ) {
    }

    /** kissj sends the six digits bare; the reader sees them as `483-291`. */
    public function formattedUserCode(): string
    {
        return strlen($this->userCode) === 6
            ? substr($this->userCode, 0, 3) . '-' . substr($this->userCode, 3)
            : $this->userCode;
    }
}
