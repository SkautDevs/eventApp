<?php

declare(strict_types=1);

namespace App\Push;

/**
 * The notifications sent on one Prague calendar day (NotificationList::groups()), newest
 * first: `key` (`news-YYYYMMDD`) is the morph's handle on the group, `label` its heading
 * (`Čt 20. 5.`).
 */
final readonly class NotificationGroup
{
    /** @param list<Notification> $items */
    public function __construct(
        public string $key,
        public string $label,
        public array $items,
    ) {}
}
