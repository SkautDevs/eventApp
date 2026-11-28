<?php

declare(strict_types=1);

namespace App\Module\Info;

use App\Auth\Authenticator;
use App\Auth\CurrentParticipant;
use App\EventConfig;
use App\Inbox\Submission;
use App\Module\Inbox\FormPage;
use App\Module\Info\View\InfoPage;
use App\Push\MessageRepository;
use App\Push\NotificationList;
use App\Toast\Toasts;
use App\View\TemplateContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The Informace screen (templates/info.twig), for ShowAction on both its addresses and for
 * Inbox\SendAction's refused form: the event's crisis contact, every notification the
 * organisers sent (not hidden on the admin page) under the day it was sent, newest first,
 * and the quick links with the handbook first; with `inbox`, the Zprávy view's form beside
 * them (FormPage), filled with `$values` under `$errors`, and the view `$view` painted.
 *
 * A message for everyone is there for every reader; a programme message appears only to a
 * paired participant whose `getParticipant()` programmes include it, under that programme's
 * name (a failing call just leaves those out — Informace logs out only on
 * `SessionExpiredException`), and in that programme's sheet on `/programy` for everyone. A
 * session kissj no longer knows logs the reader out (Profil says why), and an outage logs
 * nobody out and is not explained here, so the list simply shows what everybody sees. A
 * toast a write left (a message sent from Zprávy) is said once, on top.
 */
final class InfoScreen
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly ClockInterface $clock,
        private readonly MessageRepository $messages,
        private readonly CurrentParticipant $participant,
        private readonly Authenticator $auth,
        private readonly FormPage $form,
        private readonly Toasts $toasts,
        private readonly Twig $twig,
    ) {}

    /**
     * @param 'info'|'inbox' $view the view the address names (only `info` without the inbox)
     * @param list<string> $errors
     */
    public function render(ServerRequestInterface $request, ResponseInterface $response, string $view, Submission $values, array $errors): ResponseInterface
    {
        $event = $this->event;
        $now = $this->clock->now();
        $messages = $this->messages->visible($event->slug);
        // a sent message's, once; taken before the participant read, which clears a waiting
        // toast when it finds the session gone (Profil, not this page, says why)
        $toast = $this->toasts->take($request);

        // programme id => its current name, for the participant's own programmes only
        $mine = [];
        foreach ($this->participant->read()->participant->programmes ?? [] as $program) {
            $mine[$program->id] = $program->name;
        }
        // after the read, so a reader it logged out gets the login card, not the form
        $inbox = $event->isEnabled('inbox') ? $this->form->pane($this->auth->identity(), $values, $errors) : null;

        $page = new InfoPage(
            // optional: an event without content/emergency.php shows no contact
            emergency: $event->emergency(),
            groups: NotificationList::groups(NotificationList::items($messages, $mine, $now)),
            links: $event->links(),
            toast: $toast,
            view: $inbox !== null ? $view : 'info',
            inbox: $inbox,
        );

        return $this->toasts->mark($this->twig->render($response, 'info.twig', TemplateContext::of($page)), $toast);
    }
}
