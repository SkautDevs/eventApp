<?php

declare(strict_types=1);

namespace App\Module\Profile;

use App\Auth\PairingState;
use App\Http\RefusalPage;
use App\Http\SameOrigin;
use App\Http\WriteResponse;
use App\Program\PairingStatus;
use App\Telemetry\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * POST /profil/kontrola, `Zkontrolovat přihlášení`: the pairing wait without JavaScript.
 * The same check as the script's poll (`PairingCheck`). An approval redirects to its
 * landing (`next`: the sheet the reader started from, Moje, Přehled, or Profil on an event
 * without Program), which shows the `Jsi přihlášen` toast. Anything else goes back to
 * Profil, which shows the expired line or, still waiting, the code with
 * `PairingState::NOT_YET` for a minute (`PairingState::checked()`).
 * `SameOrigin` first (else a 403 page), as the start: a cross-site form must not collect
 * an approval for this browser. The form carries no `data-write`, since a script polls
 * instead, but the answer goes through `WriteResponse` like every other write's.
 */
final class CheckPairingAction
{
    /** The 403 page's message for a cross-site check. */
    private const string REFUSED = 'Přihlášení se nepodařilo. Načti stránku a zkus to znovu.';

    public function __construct(
        private readonly RefusalPage $refusal,
        private readonly PairingCheck $check,
        private readonly PairingState $pairingState,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $base = RouteContext::fromRequest($request)->getBasePath();
        if (!SameOrigin::allows($request)) {
            return $this->refusal->render($response, self::REFUSED, $base . '/profil', 'Zpět na profil');
        }
        $answer = Tracer::span('auth.pairing', 'POST /profil/kontrola', fn(): PairingAnswer => $this->check->run());
        $status = $answer->status;
        if ($status === PairingStatus::Approved->value && $answer->next !== null) {
            return WriteResponse::redirect($request, $response, $base . '/' . $answer->next);
        }
        if ($status === PairingStatus::Pending->value || $status === PairingStatus::SlowDown->value) {
            $this->pairingState->checked();
        }

        return WriteResponse::redirect($request, $response, $base . '/profil');
    }
}
