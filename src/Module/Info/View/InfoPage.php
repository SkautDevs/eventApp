<?php

declare(strict_types=1);

namespace App\Module\Info\View;

use App\Event\Emergency;
use App\Event\Link;
use App\Push\NotificationGroup;
use App\Toast\Toast;

/**
 * Everything templates/info.twig is rendered with, one property per template variable: the
 * crisis contact (`content/emergency.php`, null for an event without the file), the reader's
 * notifications grouped by day, the quick links (`content/links.php`, `[]` without the
 * file), the toast a write left for this page (a sent message's), or null, and whether the
 * event has the inbox (`inbox`), which puts the card to the message form under the contact.
 * ShowAction renders get_object_vars() of it.
 */
final readonly class InfoPage
{
    /**
     * @param list<NotificationGroup> $groups
     * @param list<Link> $links
     */
    public function __construct(
        public ?Emergency $emergency,
        public array $groups,
        public array $links,
        public ?Toast $toast,
        public bool $inbox,
    ) {}
}
