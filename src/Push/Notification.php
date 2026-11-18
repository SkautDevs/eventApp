<?php

declare(strict_types=1);

namespace App\Push;

/**
 * One sent notification as a reader sees it (NotificationList::items()), on Informace and
 * on Domů: `when` is the server's relative time and `at` the same instant as DATE_ATOM,
 * from which www/js/ui/reltime.js redraws `when` on the device clock; `sentAt` is that instant in
 * Prague, which the day groups are made from. `programme` is the name of the programme the
 * message is about, or null for a message to everyone.
 */
final readonly class Notification
{
    public function __construct(
        public int $id,
        public string $when,
        public string $at,
        public \DateTimeImmutable $sentAt,
        public string $title,
        public string $body,
        public ?string $programme,
    ) {}
}
