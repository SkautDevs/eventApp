<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Event\Emergency;
use App\Inbox\Submission;

/**
 * Everything templates/_inbox_pane.twig, the Zprávy view of Informace, reads as `inbox.*`:
 * who the reader writes as (the end of `Píšeš jako …`, null for a reader who is not logged
 * in, who gets the login card instead of the form), the event's promise line (null for an
 * event without one, whose form shows none), the event's crisis contact
 * (`content/emergency.php`, null without the file; its numbers are the form's call
 * buttons), the values the form is filled with and the errors above it. FormPage builds it.
 */
final readonly class InboxPage
{
    /** @param list<string> $errors */
    public function __construct(
        public ?string $sender,
        public ?string $promise,
        public ?Emergency $emergency,
        public Submission $values,
        public array $errors,
    ) {}
}
