<?php

declare(strict_types=1);

namespace App\Module\Info;

use App\Auth\CurrentParticipant;
use App\EventConfig;
use App\Module\Info\View\InfoPage;
use App\Push\MessageRepository;
use App\Push\NotificationList;
use App\View\TemplateContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * GET /informace: the event's crisis contact, every notification the organisers sent (not
 * hidden on the admin page) under the day it was sent, newest first, and the quick links
 * with the handbook first. A message for everyone is there for every reader; a programme
 * message appears only to a paired participant whose `getParticipant()` programmes include
 * it, under that programme's name (a failing call just leaves those out — Informace logs
 * out only on `SessionExpiredException`), and in that programme's sheet on `/programy` for
 * everyone. A session kissj no longer knows logs the reader out (Profil says why), and an
 * outage logs nobody out and is not explained here, so the list simply shows what
 * everybody sees.
 */
final class ShowAction
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly ClockInterface $clock,
        private readonly MessageRepository $messages,
        private readonly CurrentParticipant $participant,
        private readonly Twig $twig,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $event = $this->event;
        $now = $this->clock->now();
        $messages = $this->messages->visible($event->slug);

        // programme id => its current name, for the participant's own programmes only
        $mine = [];
        foreach ($this->participant->read()->participant->programmes ?? [] as $program) {
            $mine[$program->id] = $program->name;
        }

        $page = new InfoPage(
            // optional: an event without content/emergency.php shows no contact
            emergency: $event->emergency(),
            groups: NotificationList::groups(NotificationList::items($messages, $mine, $now)),
            links: $event->links(),
        );

        return $this->twig->render($response, 'info.twig', TemplateContext::of($page));
    }
}
