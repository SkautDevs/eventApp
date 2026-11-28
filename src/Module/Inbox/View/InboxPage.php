<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Inbox\Submission;

/**
 * Everything templates/inbox.twig is rendered with, one property per template variable: who
 * the reader writes as (the end of `Píšeš jako …`, null for a reader who is not logged in,
 * who gets the login card instead of the form), the event's promise line, the values the
 * form is filled with and the errors above it. ShowAction renders get_object_vars() of it.
 */
final readonly class InboxPage
{
    /** @param list<string> $errors */
    public function __construct(
        public ?string $sender,
        public string $promise,
        public Submission $values,
        public array $errors,
    ) {}
}
