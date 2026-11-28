<?php

declare(strict_types=1);

namespace App\Module\Inbox;

use App\Auth\Identity;
use App\EventConfig;
use App\Inbox\Submission;
use App\Module\Inbox\View\InboxPage;

/**
 * The Zprávy view of Informace (templates/_inbox_pane.twig) for one reader: the form signed
 * with their nickname (or their participant number without one), filled with `$values`
 * under `$errors` and below the event's crisis numbers, or the login card for a reader who
 * is not paired. Info\InfoScreen builds it empty for both addresses of the screen, and
 * with what a refused send typed for SendAction.
 */
final class FormPage
{
    public function __construct(private readonly EventConfig $event) {}

    /** @param list<string> $errors */
    public function pane(?Identity $identity, Submission $values, array $errors): InboxPage
    {
        $nickname = trim($identity->nickname ?? '');

        return new InboxPage(
            sender: match (true) {
                $identity === null => null,
                $nickname !== '' => $nickname,
                default => 'účastník č. ' . $identity->participantId,
            },
            promise: $this->event->inboxPromise(),
            // optional: without content/emergency.php the form offers no number to call
            emergency: $this->event->emergency(),
            values: $values,
            errors: $errors,
        );
    }
}
