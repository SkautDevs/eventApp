<?php

declare(strict_types=1);

namespace App\Inbox;

/** What a participant's message is about; the value is what `inbox_messages.kind` stores. */
enum Kind: string
{
    case Help = 'help';
    case Idea = 'idea';
}
