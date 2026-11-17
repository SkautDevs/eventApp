<?php

declare(strict_types=1);

namespace App\Program;

use GuzzleHttp\Exception\TransferException;

/**
 * Where an event's programme comes from: kissj (docs/kissj-contract.md) or the event's
 * local fixture files. Every method may throw TransferException or ProgramDataException
 * when the source fails; the personal ones throw SessionExpiredException when the device
 * session is no longer valid, which logs the reader out.
 */
interface ProgramProviderInterface
{
    /** @return list<array> programs in the internal shape, each with `preregistered` (bool) */
    public function getPrograms(): array;

    /**
     * The event's programme sections — the detail sheet names a programme's section,
     * and a programme whose section is not listed here is not shown.
     *
     * @return array<int, array{id: int, title: string, subTitle: ?string}>
     *         keyed by id, in display order
     */
    public function getSections(): array;

    /** The event's programme registration window; either end may be missing. */
    public function getRegistrationWindow(): RegistrationWindow;

    /**
     * The participant the device session is bound to, with their active registrations.
     *
     * @throws SessionExpiredException when the session is unknown, expired or revoked
     * @throws TransferException|ProgramDataException when the provider fails
     */
    public function getParticipant(string $sessionToken): Participant;

    /**
     * The ids of every participant registered for this programme.
     *
     * @return list<int>
     * @throws TransferException|ProgramDataException when the provider fails
     */
    public function getParticipantIdsForProgramme(int $programmeId): array;

    /**
     * @param string $deviceId 64 lowercase hex, the SHA-256 of eventApp's session id
     * @throws TransferException|ProgramDataException when the provider fails (kissj's 429 included)
     */
    public function startPairing(string $deviceId): Pairing;

    /** @throws TransferException|ProgramDataException when the provider fails */
    public function pollPairing(string $deviceCode): PairingPoll;

    /**
     * @throws RegistrationRefusedException with the contract's code when the source says no
     * @throws SessionExpiredException when the session is unknown, expired or revoked
     * @throws TransferException|ProgramDataException when the provider fails
     */
    public function register(string $sessionToken, int $programmeId): void;

    /**
     * @throws RegistrationRefusedException with the contract's code when the source says no
     * @throws SessionExpiredException when the session is unknown, expired or revoked
     * @throws TransferException|ProgramDataException when the provider fails
     */
    public function unregister(string $sessionToken, int $programmeId): void;

    /**
     * Ends the device session at the source; the token answers as expired from then on.
     *
     * @throws SessionExpiredException when the session already is not valid
     * @throws TransferException|ProgramDataException when the provider fails
     */
    public function revokeSession(string $sessionToken): void;
}
