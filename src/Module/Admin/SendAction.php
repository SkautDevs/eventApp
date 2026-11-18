<?php

declare(strict_types=1);

namespace App\Module\Admin;

use App\EventConfig;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\ProgramUnavailableException;
use App\Push\MessageRepository;
use App\Push\PushSenderInterface;
use App\Push\SubscriptionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;

/**
 * POST /admin/notify: sends a notification to everyone or to one programme's registered
 * participants. The form is validated, the programme's participants are read, and only
 * then is the form's one-time nonce used up, so a double tap or a re-send after a 504
 * sends nothing (409, the text handed back). The result is flashed for the GET the 303
 * lands on (Post/Redirect/Get: a refresh must not send it again).
 *
 * Every send needs a Podpis (log only, never in the notification) and is written to the
 * `messages` log (`App\Push\MessageRepository`, same database) **before** it is sent —
 * `begin()` stores it with NULL counts and `finish()` fills `sent`, `removed`, `failed` and
 * `unreached` (a programme's participants with no subscription) when the send returns, so a
 * send that dies half-way still leaves the message on Informace, with `–` for the counts in
 * the admin log; the result line adds `nedoručeno: N` when anything failed and
 * `přihlášených bez notifikací: N` for a programme send.
 */
final class SendAction
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly AdminSession $admin,
        private readonly AdminPage $page,
        private readonly ProgramProviderInterface $provider,
        private readonly MessageRepository $messages,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PushSenderInterface $sender,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (!$this->admin->isOpen() || !$this->admin->csrfMatches($body['csrf'] ?? null)) {
            return $this->page->refused($request, $response);
        }
        $values = [
            'target' => trim((string) ($body['target'] ?? '')),
            'title' => trim((string) ($body['title'] ?? '')),
            'body' => trim((string) ($body['body'] ?? '')),
            'signature' => trim((string) ($body['signature'] ?? '')),
        ];

        $programmesById = [];
        foreach ($this->page->programmes()[0] as $programme) {
            $programmesById[(string) $programme['id']] = $programme;
        }
        $errors = self::validate($values, $programmesById);
        if ($errors !== []) {
            return $this->page->render($request, $response->withStatus(422), ['errors' => $errors, 'values' => $values]);
        }

        $event = $this->event;
        $base = RouteContext::fromRequest($request)->getBasePath();
        $programme = $programmesById[$values['target']] ?? null;
        $participantIds = null;
        if ($programme !== null) {
            try {
                $participantIds = $this->provider->getParticipantIdsForProgramme((int) $programme['id']);
            } catch (ProgramUnavailableException|ProgramDataException) {
                return $this->page->render($request, $response->withStatus(502), [
                    'errors' => ['Nepodařilo se načíst přihlášené z KissJ, nic nebylo odesláno.'],
                    'values' => $values,
                ]);
            }
        }

        // Consumed only now, right before the send: a form refused above keeps its nonce,
        // so only a real send attempt uses one up.
        $given = $body['nonce'] ?? null;
        if (!is_string($given) || !$this->admin->consumeNonce($given)) {
            // The form was sent already (a double tap, a retry after a timeout) or is from
            // before the session: nothing goes out, and the text comes back for a deliberate re-send.
            return $this->page->render($request, $response->withStatus(409), [
                'notice' => 'Tento formulář už byl jednou odeslán, nebo je zastaralý, a tentokrát se nic neodeslalo. '
                    . 'Pokud zpráva v seznamu níže chybí, lze ji odeslat znovu.',
                'values' => $values,
            ]);
        }

        // Logged before the send, so a send that dies half-way still leaves the message on
        // Informace; the counts follow when it returns. An exception is not caught: it ends in the
        // error page, and the log shows the message without counts.
        $messageId = $this->messages->begin(
            event: $event->slug,
            programmeId: $programme === null ? null : (int) $programme['id'],
            targetLabel: $programme === null ? 'Všem' : (string) $programme['name'],
            title: $values['title'],
            body: $values['body'],
            signature: $values['signature'],
        );

        $result = $this->sender->sendToEvent(
            event: $event->slug,
            title: $values['title'],
            body: $values['body'],
            icon: $event->get('assets')['notificationIcon'] ?? null,
            url: $programme === null
                ? $base . '/informace'
                : sprintf('%s/programy#section-%d-program-%d', $base, $programme['section']['id'] ?? 0, $programme['id']),
            participantIds: $participantIds,
            programme: $programme === null ? null : (int) $programme['id'],
        );
        $unreached = $participantIds === null
            ? null
            : count(array_diff($participantIds, $this->subscriptions->subscribedParticipantIds($event->slug)));
        $this->messages->finish($event->slug, $messageId, $result['sent'], $result['removed'], $result['failed'], $unreached);
        // a record on stdout even with Sentry off; no participant id, no title, no body
        $this->logger->info('push.sent', [
            'event' => $event->slug,
            'sent' => $result['sent'],
            'removed' => $result['removed'],
            'failed' => $result['failed'],
            'programme' => $programme === null ? null : (int) $programme['id'],
        ]);

        // Post/Redirect/Get: a refresh of the result must not send the message again. The
        // next message usually comes from the same person, so only Podpis is kept.
        $this->admin->flashResult(
            ['sent' => $result['sent'], 'removed' => $result['removed'], 'failed' => $result['failed'], 'unreached' => $unreached],
            $values['signature'],
        );

        return $response
            ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin-notify'))
            ->withStatus(303);
    }

    /**
     * @param array{target: string, title: string, body: string, signature: string} $values
     * @param array<string, array> $programmesById
     * @return list<string> one Czech line per problem
     */
    private static function validate(array $values, array $programmesById): array
    {
        $errors = [];
        if ($values['title'] === '' || mb_strlen($values['title']) > 60) {
            $errors[] = 'Titulek je povinný a smí mít nejvýš 60 znaků.';
        }
        if ($values['body'] === '' || mb_strlen($values['body']) > 500) {
            $errors[] = 'Zpráva je povinná a smí mít nejvýš 500 znaků.';
        }
        if (!AdminPage::signatureValid($values['signature'])) {
            $errors[] = AdminPage::SIGNATURE_ERROR;
        }
        if ($values['target'] !== '' && !isset($programmesById[$values['target']])) {
            $errors[] = 'Vybraný program neexistuje.';
        }

        return $errors;
    }
}
