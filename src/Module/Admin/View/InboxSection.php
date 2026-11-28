<?php

declare(strict_types=1);

namespace App\Module\Admin\View;

use App\Inbox\InboxMessage;

/**
 * The participants' messages on the admin page (templates/_admin_inbox.twig): the unhandled
 * ones in their two groups and their count, one page of the handled ones with their count,
 * the page's number and whether an older page exists, and whether the handled list starts
 * open (its query parameter was given).
 */
final readonly class InboxSection
{
    /**
     * @param list<InboxMessage> $help unhandled, kind help, newest first
     * @param list<InboxMessage> $ideas unhandled, kind idea, newest first
     * @param list<InboxMessage> $handled one page, newest first
     */
    public function __construct(
        public array $help,
        public array $ideas,
        public int $unhandled,
        public array $handled,
        public int $handledCount,
        public int $handledPage,
        public bool $hasOlderHandled,
        public bool $open,
    ) {}

    /** The count of the link at the top of the page: `2 nevyřízené`, `5 nevyřízených`. Pure. */
    public static function unhandledLabel(int $count): string
    {
        return $count . ' ' . match (true) {
            $count === 1 => 'nevyřízená',
            $count >= 2 && $count <= 4 => 'nevyřízené',
            default => 'nevyřízených',
        };
    }

    /** unhandledLabel() of this section's count, for the template. */
    public function countLabel(): string
    {
        return self::unhandledLabel($this->unhandled);
    }
}
