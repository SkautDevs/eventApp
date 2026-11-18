<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Auth\Authenticator;
use App\Auth\CurrentParticipant;
use App\EventConfig;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\ProgramUnavailableException;
use App\Program\RegistrationWindow;
use App\Push\MessageRepository;
use App\Push\NotificationList;
use App\Toast\Toasts;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * GET /programy: the three views of the programme (ScheduleBuilder), every sheet with its
 * footer (SheetBuilder::footer()) and the organisers' messages about it, and the toast a
 * register or unregister left. The paired reader's own programmes are read best effort: a
 * session kissj no longer knows logs the reader out, and this very page says why; an
 * outage leaves a notice.
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
        $provider = $this->provider;
        $toasts = $this->toasts;
        $now = $this->clock->now();
        // Both provider calls can fail in the same request — kissj being down is
        // exactly when they do — so the notices accumulate and the template renders
        // them as one line.
        $notices = [];
        // only a list that was actually read can say "not registered" or "nothing yet"
        $mineRead = false;
        // what a register or unregister said, on the document its redirect lands on
        $toast = $toasts->take($request);

        $read = $this->participant->read();
        $mine = $read->participant->programmes ?? [];
        if ($read->participant !== null) {
            $mineRead = true;
        } elseif ($read->gone) {
            // kissj no longer knows the device session: logged out, as on Domů, and
            // this very page renders logged out, so it says why itself
            $toast = $toasts->gone();
        } elseif ($read->unavailable) {
            // never arrived, or arrived as something that is not programme data —
            // the reader is told the same thing either way
            $notices[] = 'Osobní program se nepodařilo načíst.';
        }

        $all = [];
        $sections = [];
        // no list at all, not even a stale one: the template then says so instead of "no programme"
        $unavailable = false;
        try {
            $all = $provider->getPrograms();
            $sections = $provider->getSections();
        } catch (ProgramUnavailableException|ProgramDataException) {
            $notices[] = 'Programy se nepodařilo načíst, zkus to prosím později.';
            $unavailable = true;
        }

        $model = ScheduleBuilder::buildViewModel($sections, $all, $mine, $mineRead, $now);

        // the window decides every sheet's footer; read only when there is a sheet, and
        // a window that cannot be read leaves the footers empty rather than guessing
        $window = null;
        if ($model['details'] !== []) {
            try {
                $window = $provider->getRegistrationWindow();
            } catch (ProgramUnavailableException|ProgramDataException) {
                $window = null;
            }
        }

        // the organisers' messages about a programme are in its sheet, for everybody
        $messages = [];
        foreach ($this->messages->visible($this->event->slug) as $message) {
            if ($message['programmeId'] !== null) {
                $sentAt = NotificationList::sentAt($message['sentAt']);
                $messages[$message['programmeId']][] = [
                    'when' => $sentAt === null ? $message['sentAt'] : NotificationList::relative($sentAt, $now),
                    // the instant www/shell.js redraws `when` from on the device clock
                    'at' => $sentAt?->format(\DATE_ATOM),
                    'title' => $message['title'],
                    'body' => $message['body'],
                ];
            }
        }
        // asked after the read, which may have logged the reader out
        $paired = $this->auth->isLogged();
        foreach ($model['details'] as $i => $detail) {
            $model['details'][$i]['messages'] = $messages[(int) $detail['id']] ?? [];
            $model['details'][$i]['footer'] = $window === null && $paired
                ? null
                : SheetBuilder::footer($detail, $paired, $mineRead, $window ?? new RegistrationWindow(null, null), $now);
        }

        return $toasts->mark($this->twig->render($response, 'programs.twig', $model + [
            'notice' => $notices === [] ? null : implode(' ', $notices),
            'programmesUnavailable' => $unavailable,
            'isLogged' => $paired,
            'mineRead' => $mineRead,
            'toast' => $toast,
        ]), $toast);
    }
}
