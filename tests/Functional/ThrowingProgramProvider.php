<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Program\Pairing;
use App\Program\PairingPoll;
use App\Program\Participant;
use App\Program\ProgramProviderInterface;
use App\Program\RegistrationWindow;

/**
 * Test program provider that fails where it is told to: the list, every participant read
 * or only the second and later ones, the participants of a programme. It answers no
 * pairing and no write: a test of those brings a provider of its own.
 */
final class ThrowingProgramProvider implements ProgramProviderInterface
{
    private int $participantCalls = 0;

    public function __construct(
        private readonly ?\Throwable $programsException = null,
        private readonly ?\Throwable $identityExceptionAfterFirstCall = null,
        private readonly ?\Throwable $identityException = null,
        private readonly ?\Throwable $participantIdsException = null,
    ) {
    }

    public function getPrograms(): array
    {
        if ($this->programsException !== null) {
            throw $this->programsException;
        }

        return [];
    }

    public function getSections(): array
    {
        if ($this->programsException !== null) {
            throw $this->programsException;
        }

        return [];
    }

    public function getRegistrationWindow(): RegistrationWindow
    {
        if ($this->programsException !== null) {
            throw $this->programsException;
        }

        return new RegistrationWindow(null, null);
    }

    public function getParticipant(string $sessionToken): Participant
    {
        $this->participantCalls++;

        if ($this->identityException !== null) {
            throw $this->identityException;
        }

        if ($this->participantCalls > 1 && $this->identityExceptionAfterFirstCall !== null) {
            throw $this->identityExceptionAfterFirstCall;
        }

        return new Participant(1, null, []);
    }

    public function getParticipantIdsForProgramme(int $programmeId): array
    {
        if ($this->participantIdsException !== null) {
            throw $this->participantIdsException;
        }

        return [];
    }

    public function startPairing(string $deviceId): Pairing
    {
        throw new \LogicException('ThrowingProgramProvider answers no pairing');
    }

    public function pollPairing(string $deviceCode): PairingPoll
    {
        throw new \LogicException('ThrowingProgramProvider answers no pairing');
    }

    public function register(string $sessionToken, int $programmeId): void
    {
        throw new \LogicException('ThrowingProgramProvider answers no write');
    }

    public function unregister(string $sessionToken, int $programmeId): void
    {
        throw new \LogicException('ThrowingProgramProvider answers no write');
    }

    public function revokeSession(string $sessionToken): void
    {
        throw new \LogicException('ThrowingProgramProvider answers no write');
    }
}
