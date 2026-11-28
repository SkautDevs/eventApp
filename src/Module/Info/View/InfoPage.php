<?php

declare(strict_types=1);

namespace App\Module\Info\View;

use App\Event\Emergency;
use App\Event\Link;
use App\Module\Inbox\View\InboxPage;
use App\Push\NotificationGroup;
use App\Toast\Toast;

/**
 * Everything templates/info.twig is rendered with, one property per template variable: the
 * crisis contact (`content/emergency.php`, null for an event without the file), the reader's
 * notifications grouped by day, the quick links (`content/links.php`, `[]` without the
 * file), the toast a write left for this page (a sent message's), or null, the view the
 * address names (`info` or `inbox`; always `info` without the inbox) and the Zprávy view's
 * form (`_inbox_pane.twig`), null for an event without `inbox`, whose screen has one view
 * and no pills. InfoScreen renders get_object_vars() of it.
 */
final readonly class InfoPage
{
    /**
     * @param list<NotificationGroup> $groups
     * @param list<Link> $links
     * @param 'info'|'inbox' $view
     */
    public function __construct(
        public ?Emergency $emergency,
        public array $groups,
        public array $links,
        public ?Toast $toast,
        public string $view,
        public ?InboxPage $inbox,
    ) {}
}
