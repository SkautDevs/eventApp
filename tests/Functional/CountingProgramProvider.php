<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Program\Pairing;
use App\Program\PairingPoll;
use App\Program\Participant;
use App\Program\ProgramProviderInterface;
use App\Program\RegistrationWindow;

/** Delegates to a real provider and counts the personal calls — "did it reach kissj?". */
final class CountingProgramProvider implements ProgramProviderInterface
{
    public int $participantCalls = 0;

    public int $pairingStarts = 0;

    public int $pairingPolls = 0;

    public int $revokes = 0;

    /** @var list<array{string, int}> register and unregister calls, as [kind, programme id] */
    public array $writes = [];

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
        $this->participantCalls++;

        return $this->inner->getParticipant($sessionToken);
    }

    public function getParticipantIdsForProgramme(int $programmeId): array
    {
        return $this->inner->getParticipantIdsForProgramme($programmeId);
    }

    public function startPairing(string $deviceId): Pairing
    {
        $this->pairingStarts++;

        return $this->inner->startPairing($deviceId);
    }

    public function pollPairing(string $deviceCode): PairingPoll
    {
        $this->pairingPolls++;

        return $this->inner->pollPairing($deviceCode);
    }

    public function register(string $sessionToken, int $programmeId): void
    {
        $this->writes[] = ['register', $programmeId];
        $this->inner->register($sessionToken, $programmeId);
    }

    public function unregister(string $sessionToken, int $programmeId): void
    {
        $this->writes[] = ['unregister', $programmeId];
        $this->inner->unregister($sessionToken, $programmeId);
    }

    public function revokeSession(string $sessionToken): void
    {
        $this->revokes++;
        $this->inner->revokeSession($sessionToken);
    }
}
