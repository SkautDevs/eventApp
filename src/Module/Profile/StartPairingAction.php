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
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
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
            return $response->withHeader('Location', $base . '/profil')->withStatus(302);
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
            try {
                $pairing = $this->provider->startPairing(hash('sha256', $session->id()));
                $pairingState->begin($pairing);
                Tracer::tag('pairing_outcome', 'started');
            } catch (ProgramUnavailableException|ProgramDataException) {
                // never arrived, kissj's cap, or an answer that is not a pairing: one notice
                $pairingState->fail('unavailable');
                Tracer::tag('pairing_outcome', 'provider_failed');
            }
        });

        return $response->withHeader('Location', $base . '/profil')->withStatus(302);
    }
}
