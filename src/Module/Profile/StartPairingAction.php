<?php

declare(strict_types=1);

namespace App\Module\Profile;

use App\Auth\Authenticator;
use App\Auth\PairingState;
use App\Auth\PairingThrottle;
use App\EventConfig;
use App\Http\ClientIp;
use App\Http\RefusalPage;
use App\Http\SameOrigin;
use App\Http\WriteResponse;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\Programme;
use App\Program\ProgramUnavailableException;
use App\Session;
use App\Telemetry\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * POST /profil/propojit: starts a pairing for this browser. kissj gives a code the reader
 * enters in KissJ, and Profil polls until it is approved. The device id is the hash of the
 * session ID, so a second start replaces this browser's pending pairing at kissj. The
 * throttle is a lazy service: the database opens only once the checks before it passed.
 *
 * The form's optional `program` field (a programme's sheet links to `profil?program=<id>`,
 * and both start forms carry it on) names the sheet the approval returns to. It is kept
 * (PairingState's pairingReturn) only when it is a positive decimal id the provider lists
 * now; the list is read only when the field is sent and the throttle let the start
 * through, and a provider failure drops the target, not the start.
 */
final class StartPairingAction
{
    /** The 403 page's message for a cross-site start. */
    private const string REFUSED = 'Přihlášení se nepodařilo. Načti stránku a zkus to znovu.';

    public function __construct(
        private readonly RefusalPage $refusal,
        private readonly Session $session,
        private readonly Authenticator $auth,
        private readonly EventConfig $event,
        private readonly ClientIp $clientIp,
        private readonly PairingThrottle $throttle,
        private readonly PairingState $pairingState,
        private readonly ProgramProviderInterface $provider,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $base = RouteContext::fromRequest($request)->getBasePath();
        // a cross-site form must not start a pairing the attacker would then approve
        if (!SameOrigin::allows($request)) {
            return $this->refusal->render($response, self::REFUSED, $base . '/profil', 'Zpět na profil');
        }
        $session = $this->session;
        // already paired: nothing to start, and no attempt to count
        if ($this->auth->isLogged()) {
            return WriteResponse::redirect($request, $response, $base . '/profil');
        }

        Tracer::span('auth.pairing', 'POST /profil/propojit', function () use ($request, $session): void {
            $slug = $this->event->slug;
            $ip = $this->clientIp->throttleKey($request);
            $throttle = $this->throttle;
            $pairingState = $this->pairingState;
            // every start is a pending pairing at kissj, which caps them per event
            if ($throttle->tooMany($slug, $ip)) {
                $pairingState->fail('busy');
                Tracer::tag('pairing_outcome', 'rate_limited');

                return;
            }
            $throttle->record($slug, $ip);
            $returnTo = $this->returnTo($request);
            try {
                $pairing = $this->provider->startPairing(hash('sha256', $session->id()));
                $pairingState->begin($pairing, $returnTo);
                Tracer::tag('pairing_outcome', 'started');
            } catch (ProgramUnavailableException|ProgramDataException) {
                // never arrived, kissj's cap, or an answer that is not a pairing: one notice
                $pairingState->fail('unavailable');
                Tracer::tag('pairing_outcome', 'provider_failed');
            }
        });

        return WriteResponse::redirect($request, $response, $base . '/profil');
    }

    /**
     * The programme the form's `program` names, as the provider lists it now, or null: no
     * field, not a positive decimal id, an event without Program, an id not in the list, or
     * a provider that fails to answer.
     */
    private function returnTo(ServerRequestInterface $request): ?Programme
    {
        $body = $request->getParsedBody();
        $id = PairingState::programId(is_array($body) ? ($body['program'] ?? null) : null);
        if ($id === null || !$this->event->isEnabled('programs')) {
            return null;
        }
        try {
            foreach ($this->provider->getPrograms() as $programme) {
                if ($programme->id === $id) {
                    return $programme;
                }
            }
        } catch (ProgramUnavailableException|ProgramDataException) {
            // the target goes, the pairing still starts
        }

        return null;
    }
}
