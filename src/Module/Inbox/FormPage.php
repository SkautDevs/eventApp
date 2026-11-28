<?php

declare(strict_types=1);

namespace App\Module\Inbox;

use App\Auth\Identity;
use App\EventConfig;
use App\Inbox\Submission;
use App\Module\Inbox\View\InboxPage;
use App\View\TemplateContext;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;

/**
 * templates/inbox.twig for one reader: the form signed with their nickname (or their
 * participant number without one), filled with `$values` under `$errors`, or the login card
 * for a reader who is not paired. ShowAction renders it empty, SendAction with what a
 * refused send typed and the status of the refusal.
 */
final class FormPage
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly Twig $twig,
    ) {}

    /** @param list<string> $errors */
    public function render(ResponseInterface $response, ?Identity $identity, Submission $values, array $errors): ResponseInterface
    {
        $nickname = trim($identity->nickname ?? '');

        $page = new InboxPage(
            sender: match (true) {
                $identity === null => null,
                $nickname !== '' => $nickname,
                default => 'účastník č. ' . $identity->participantId,
            },
            promise: $this->event->inboxPromise(),
            values: $values,
            errors: $errors,
        );

        return $this->twig->render($response, 'inbox.twig', TemplateContext::of($page));
    }
}
