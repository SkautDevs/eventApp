<?php

declare(strict_types=1);

namespace App\Push;

use App\Storage\Row;

/**
 * One row of the `messages` log as MessageRepository reads it: what an organiser sent, to
 * whom (`programmeId`, null for everyone, under `targetLabel`), the counts its send came to
 * — all four null while the send runs, and for good when it died — and the last hide or
 * show (`toggledAt`, `toggledBy`). `sentAt` and `toggledAt` stay the stored local-time
 * strings (`Y-m-d H:i:s`): the admin page formats them with Twig's `date` filter and
 * NotificationList reads `sentAt` as Prague time.
 */
final readonly class Message
{
    public function __construct(
        public int $id,
        public string $event,
        public string $sentAt,
        public ?int $programmeId,
        public string $targetLabel,
        public string $title,
        public string $body,
        public string $signature,
        public ?int $sent,
        public ?int $removed,
        public ?int $failed,
        public ?int $unreached,
        public bool $hidden,
        public ?string $toggledAt,
        public ?string $toggledBy,
    ) {}

    /**
     * A `messages` row as PDO fetches it.
     *
     * @param array<mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: Row::int($row, 'id'),
            event: Row::string($row, 'event'),
            sentAt: Row::string($row, 'sent_at'),
            programmeId: Row::nullableInt($row, 'programme_id'),
            targetLabel: Row::string($row, 'target_label'),
            title: Row::string($row, 'title'),
            body: Row::string($row, 'body'),
            signature: Row::string($row, 'signature'),
            sent: Row::nullableInt($row, 'sent'),
            removed: Row::nullableInt($row, 'removed'),
            failed: Row::nullableInt($row, 'failed'),
            unreached: Row::nullableInt($row, 'unreached'),
            hidden: (bool) $row['hidden'],
            toggledAt: Row::nullableString($row, 'toggled_at'),
            toggledBy: Row::nullableString($row, 'toggled_by'),
        );
    }
}
