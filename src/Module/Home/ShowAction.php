<?php

declare(strict_types=1);

namespace App\Module\Home;

use App\Auth\Authenticator;
use App\Auth\CurrentParticipant;
use App\EventConfig;
use App\Module\Home\View\HomePage;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\ProgramUnavailableException;
use App\Program\RegistrationWindow;
use App\Push\MessageRepository;
use App\Push\NotificationList;
use App\Toast\Toasts;
use App\View\TemplateContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * GET / (Domů), built by HomeModel from the event's dates. Before the start nothing
 * personal is read; from it the participant's programmes, the sections and the messages
 * are read best effort — a `SessionExpiredException` logs the reader out and this very
 * page carries the toast that says why, an outage just leaves its part out. The message
 * log is a lazy service: it is read, and the database opened, only from the event's start.
 */
final class ShowAction
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly ClockInterface $clock,
        private readonly ProgramProviderInterface $provider,
        private readonly CurrentParticipant $participant,
        private readonly Authenticator $auth,
        private readonly Toasts $toasts,
        private readonly MessageRepository $messages,
        private readonly Twig $twig,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $event = $this->event;
        $now = $this->clock->now();
        $phase = HomeModel::phase($event, $now);
        $programs = in_array('programs', $event->features, true);
        $provider = $programs ? $this->provider : null;

        // Every provider read is best effort: whatever the provider says, the homepage
        // renders. A session kissj no longer knows logs the reader out (Profil says
        // why); an outage logs nobody out and only leaves its part of the screen out.
        // Before the start the screen shows no programme of the day, so nothing
        // personal is read.
        $mine = [];
        $toast = null;
        if ($provider !== null && $phase !== 'before') {
            $read = $this->participant->read();
            $mine = $read->participant->programmes ?? [];
            if ($read->gone) {
                // this very page renders logged out, so it says why itself
                $toast = $this->toasts->gone();
            }
        }
        $sections = [];
        if ($provider !== null && $mine !== []) {
            try {
                $sections = $provider->getSections();
            } catch (ProgramUnavailableException|ProgramDataException) {
                // the cards lose their third line, nothing else
            }
        }
        $window = new RegistrationWindow(null, null);
        if ($provider !== null && $phase === 'before') {
            try {
                $window = $provider->getRegistrationWindow();
            } catch (ProgramUnavailableException|ProgramDataException) {
                // no window card
            }
        }
        // today's notifications, from the start: the ones this reader may see
        $news = [];
        if ($phase !== 'before' && in_array('info', $event->features, true)) {
            $names = [];
            foreach ($mine as $programme) {
                $names[$programme->id] = $programme->name;
            }
            $news = NotificationList::items($this->messages->visible($event->slug), $names, $now);
        }

        $page = new HomePage(
            links: $event->links(),
            // asked after the read, which may have logged the reader out
            home: HomeModel::build($event, $news, $mine, $sections, $this->auth->isLogged(), $window, $now),
            toast: $toast,
        );

        return $this->toasts->mark($this->twig->render($response, 'homepage.twig', TemplateContext::of($page)), $toast);
    }
}
