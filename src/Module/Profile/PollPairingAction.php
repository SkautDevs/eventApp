<?php

declare(strict_types=1);

namespace App\Module\Profile;

use App\Auth\Authenticator;
use App\Auth\Identity;
use App\Auth\PairingState;
use App\Program\PairingStatus;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\ProgramUnavailableException;
use App\Telemetry\Collector;
use App\Telemetry\Tracer;
use App\Toast\Toasts;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /profil/propojit/stav: what Profil's script asks every 5 s while the code is shown
 * (www/profile.js). It is always a 200 with one of the contract's statuses: "pending" (and
 * "slowDown") mean ask again, "approved" means the reader is now logged in, "expired" ends
 * the pairing and leaves the line to Profil. A failure is "pending" too: the page keeps
 * asking and the pairing stays, so kissj's re-issued approval is collected later.
 */
final class PollPairingAction
{
    public function __construct(
        private readonly PairingState $pairingState,
        private readonly ProgramProviderInterface $provider,
        private readonly Authenticator $auth,
        private readonly Toasts $toasts,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $status = Tracer::span('auth.pairing', 'GET /profil/propojit/stav', function (): string {
            $pairingState = $this->pairingState;
            $pairing = $pairingState->pending();
            if ($pairing === null) {
                return PairingStatus::Expired->value;
            }
            $provider = $this->provider;
            try {
                $poll = $provider->pollPairing($pairing->deviceCode);
            } catch (ProgramUnavailableException|ProgramDataException $e) {
                Collector::collect($e);

                return PairingStatus::Pending->value;
            }

            switch ($poll->status) {
                case PairingStatus::Approved:
                    try {
                        if ($poll->sessionToken === null) {
                            throw new ProgramDataException('An approved pairing without a session token');
                        }
                        $participant = $provider->getParticipant($poll->sessionToken);
                    } catch (\Throwable $e) {
                        // nothing is stored and the pairing stays: the next poll collects
                        // the approval kissj re-issues within a minute
                        Collector::collect($e);

                        return PairingStatus::Pending->value;
                    }
                    // the pairing goes before the ID rotates, so the old ID holds neither it
                    // nor the identity: a late poll under it cannot collect a re-issued
                    // approval, which would end the token just stored. An old outcome
                    // line goes with it: the reader is in now.
                    $pairingState->approved();
                    $this->auth->store(new Identity($participant->id, $participant->nickname, $poll->sessionToken));
                    // after the rotation: only the new ID carries it to the Profil the page reloads
                    $this->toasts->loggedIn();
                    Tracer::tag('pairing_outcome', 'approved');

                    return $poll->status->value;
                case PairingStatus::Expired:
                    $outcome = $poll->status->value;
                    $pairingState->fail($outcome);
                    Tracer::tag('pairing_outcome', $outcome);

                    return $outcome;
                default:
                    return $poll->status->value;
            }
        });
        $response->getBody()->write((string) json_encode(['status' => $status]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
