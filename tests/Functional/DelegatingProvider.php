<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Program\Pairing;
use App\Program\PairingPoll;
use App\Program\Participant;
use App\Program\ProgramProviderInterface;
use App\Program\RegistrationWindow;

/** Passes everything through: a test overrides the one call it is about. */
abstract class DelegatingProvider implements ProgramProviderInterface
{
    public function __construct(private readonly ProgramProviderInterface $inner)
    {
    }

    public function getPrograms(): array
    {
        return $this->inner->getPrograms();
    }

    public function getSections(): array
    {
        return $this->inner->getSections();
    }

    public function getRegistrationWindow(): RegistrationWindow
    {
        return $this->inner->getRegistrationWindow();
    }

    public function getParticipant(string $sessionToken): Participant
    {
        return $this->inner->getParticipant($sessionToken);
    }

    public function getParticipantIdsForProgramme(int $programmeId): array
    {
        return $this->inner->getParticipantIdsForProgramme($programmeId);
    }

    public function startPairing(string $deviceId): Pairing
    {
        return $this->inner->startPairing($deviceId);
    }

    public function pollPairing(string $deviceCode): PairingPoll
    {
        return $this->inner->pollPairing($deviceCode);
    }

    public function register(string $sessionToken, int $programmeId): void
    {
        $this->inner->register($sessionToken, $programmeId);
    }

    public function unregister(string $sessionToken, int $programmeId): void
    {
        $this->inner->unregister($sessionToken, $programmeId);
    }

    public function revokeSession(string $sessionToken): void
    {
        $this->inner->revokeSession($sessionToken);
    }
}
