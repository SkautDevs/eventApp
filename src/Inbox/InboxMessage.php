<?php

declare(strict_types=1);

namespace App\Inbox;

use App\Storage\Row;

/**
 * One row of `inbox_messages` as InboxRepository reads it: what a participant sent, as whom
 * (`participantId`, and `nickname` as kissj gave it at send time), and the last mark as
 * handled (`handledAt`, `handledBy`, both null while it is not). `sentAt` and `handledAt`
 * stay the stored local-time strings (`Y-m-d H:i:s`), as Push\Message's do.
 */
final readonly class InboxMessage
{
    public function __construct(
        public int $id,
        public string $event,
        public string $sentAt,
        public int $participantId,
        public ?string $nickname,
        public Kind $kind,
        public string $body,
        public ?string $contact,
        public bool $handled,
        public ?string $handledAt,
        public ?string $handledBy,
    ) {}

    /**
     * An `inbox_messages` row as PDO fetches it.
     *
     * @param array<mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: Row::int($row, 'id'),
            event: Row::string($row, 'event'),
            sentAt: Row::string($row, 'sent_at'),
            participantId: Row::int($row, 'participant_id'),
            nickname: Row::nullableString($row, 'nickname'),
            kind: Kind::from(Row::string($row, 'kind')),
            body: Row::string($row, 'body'),
            contact: Row::nullableString($row, 'contact'),
            handled: Row::int($row, 'handled') === 1,
            handledAt: Row::nullableString($row, 'handled_at'),
            handledBy: Row::nullableString($row, 'handled_by'),
        );
    }

    /** `<nickname> · č. <id>`, or `Účastník · č. <id>` without a nickname. */
    public function sender(): string
    {
        return ($this->nickname ?? 'Účastník') . ' · č. ' . $this->participantId;
    }
}
