<?php

declare(strict_types=1);

namespace App\Auth;

use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\ProgramUnavailableException;
use App\Program\SessionExpiredException;

/**
 * The paired reader's participant, read from the provider best effort. A reader who is not
 * paired asks the provider nothing. A session kissj no longer knows logs the reader out
 * (Authenticator::sessionGone(), so Profil says why); an outage or bad data logs nobody
 * out. Any other failure is not caught.
 */
final class CurrentParticipant
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly ProgramProviderInterface $provider,
    ) {}

    public function read(): ParticipantRead
    {
        $identity = $this->auth->identity();
        if ($identity === null) {
            return ParticipantRead::none();
        }
        try {
            return ParticipantRead::read($this->provider->getParticipant($identity->sessionToken));
        } catch (SessionExpiredException) {
            $this->auth->sessionGone();

            return ParticipantRead::gone();
        } catch (ProgramUnavailableException|ProgramDataException) {
            return ParticipantRead::unavailable();
        }
    }
}
