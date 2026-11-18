<?php

declare(strict_types=1);

namespace App\Module\Admin\View;

/**
 * The result line of the last send, shown once on the page the send redirected to:
 * delivered, removed, failed, and, for a programme send only, the registered participants
 * without a subscription (null for a send to everyone). The session keeps it as the array
 * SendAction flashed (`notifyResult`); fromArray() reads that.
 */
final readonly class NotifyResult
{
    public function __construct(
        public int $sent,
        public int $removed,
        public int $failed,
        public ?int $unreached,
    ) {}

    /** @param array<mixed> $result the flashed `{sent, removed, failed, unreached}` */
    public static function fromArray(array $result): self
    {
        $int = static fn(mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $unreached = $result['unreached'] ?? null;

        return new self(
            $int($result['sent'] ?? 0),
            $int($result['removed'] ?? 0),
            $int($result['failed'] ?? 0),
            is_numeric($unreached) ? (int) $unreached : null,
        );
    }
}
