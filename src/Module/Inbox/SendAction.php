<?php

declare(strict_types=1);

namespace App\Module\Inbox;

use App\Auth\Authenticator;
use App\EventConfig;
use App\Http\RefusalPage;
use App\Http\SameOrigin;
use App\Http\WriteResponse;
use App\Inbox\InboxWriter;
use App\Inbox\Kind;
use App\Inbox\Submission;
use App\Inbox\WriteOutcome;
use App\Toast\Toasts;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * POST /zpravy, the send of Napiš organizátorům. A cross-site form or a reader who is not
 * paired gets a page of its own (403); a form that does not validate comes back filled with
 * what was typed under its errors (422), and so does a send over the hourly limit (429).
 * Otherwise InboxWriter stores the message, or recognises a repeat of one just stored, and
 * both leave the same toast and send the reader to Informace (WriteResponse: a redirect, or
 * `{"next"}` for the script), where www/js/inbox/ reads SENT_HASH as the confirmed send.
 * The identity comes from the session alone; the send never calls kissj.
 */
final class SendAction
{
    /** The hash of the landing on Informace after a confirmed send. */
    private const string SENT_HASH = '#odeslano';

    /** The 403 page's message for a cross-site form. */
    private const string CROSS_SITE = 'Zprávu se nepodařilo odeslat. Načti stránku a zkus to znovu.';

    private const string NOT_PAIRED = 'Zprávu můžeš poslat až po přihlášení.';

    private const string SENT = 'Zpráva odeslána, díky!';

    public function __construct(
        private readonly EventConfig $event,
        private readonly RefusalPage $refusal,
        private readonly Authenticator $auth,
        private readonly InboxWriter $writer,
        private readonly FormPage $form,
        private readonly Toasts $toasts,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $routes = RouteContext::fromRequest($request)->getRouteParser();
        // a cross-site form must not send a message in the reader's name
        if (!SameOrigin::allows($request)) {
            return $this->refusal->render($response, self::CROSS_SITE, $routes->urlFor('inbox'), 'Zpět na formulář');
        }
        $identity = $this->auth->identity();
        if ($identity === null) {
            return $this->refusal->render($response, self::NOT_PAIRED, $routes->urlFor('profile'), 'Přihlásit se');
        }
        $submission = Submission::fromForm((array) $request->getParsedBody());
        $errors = $submission->errors();
        if ($errors !== []) {
            return $this->form->render($response->withStatus(422), $identity, $submission, $errors);
        }

        $outcome = $this->writer->write($this->event->slug, $identity->participantId, $identity->nickname, $submission);
        if ($outcome === WriteOutcome::Throttled) {
            $limit = sprintf('Za hodinu můžeš poslat nejvýš %d zpráv. Zkus to později.', InboxWriter::PER_HOUR);

            return $this->form->render($response->withStatus(429), $identity, $submission, [$limit]);
        }
        // a repeat is the same send arriving twice, so it answers as the success it was
        $this->toasts->put(self::SENT, $submission->kind === Kind::Help ? $this->event->inboxPromise() : null);

        return WriteResponse::redirect($request, $response, $routes->urlFor('info') . self::SENT_HASH);
    }
}
