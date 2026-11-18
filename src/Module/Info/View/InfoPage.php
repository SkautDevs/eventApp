<?php

declare(strict_types=1);

namespace App\Module\Info\View;

use App\Event\Emergency;
use App\Event\Link;
use App\Push\NotificationGroup;

/**
 * Everything templates/info.twig is rendered with, one property per template variable: the
 * crisis contact (`content/emergency.php`, null for an event without the file), the reader's
 * notifications grouped by day, and the quick links (`content/links.php`, `[]` without the
 * file). ShowAction renders get_object_vars() of it.
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
    ) {}
}
