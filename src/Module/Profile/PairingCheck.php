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

/**
 * One check of the pending pairing at the source, shared by Profil's poll
 * (`GET /profil/propojit/stav`, the script's) and the no-JS check button
 * (`POST /profil/kontrola`). It returns one of the contract's statuses as its value:
 * "pending" (and "slowDown") mean not yet; "approved" means the reader is now logged in,
 * with the `Jsi přihlášen` toast waiting for the next full Profil; "expired" ends the
 * pairing and leaves the line to Profil. No pending pairing is "expired". A failure is
 * "pending" too and the pairing stays, so kissj's re-issued approval is collected later.
 */
final class PairingCheck
{
    public function __construct(
        private readonly PairingState $pairingState,
        private readonly ProgramProviderInterface $provider,
        private readonly Authenticator $auth,
        private readonly Toasts $toasts,
    ) {}

    public function run(): string
    {
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
                    // nothing is stored and the pairing stays: the next check collects
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
                // after the rotation: only the new ID carries it to the Profil loaded next
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
    }
}
