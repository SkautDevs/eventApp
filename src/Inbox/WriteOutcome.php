<?php

declare(strict_types=1);

namespace App\Inbox;

/**
 * What InboxWriter::write() did with a valid submission: stored it, recognised it as a
 * repeat of one stored a moment ago (nothing stored, answered as a success), or refused it
 * because the participant is over the hourly limit (nothing stored).
 */
enum WriteOutcome
{
    case Saved;
    case Repeated;
    case Throttled;
}
