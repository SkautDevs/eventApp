<?php

declare(strict_types=1);

namespace App\Program;

/**
 * Where an event's programme comes from: kissj (its API contract 4.0) or the event's local
 * fixture files.
 * Every method may throw ProgramUnavailableException or ProgramDataException when the
 * source fails; the personal ones throw SessionExpiredException when the device session
 * is no longer valid, which logs the reader out.
 */
interface ProgramProviderInterface
{
    /** @return list<Programme> */
    public function getPrograms(): array;

    /**
     * The event's programme sections — the detail sheet names a programme's section,
     * and a programme whose section is not listed here is not shown.
     *
     * @return array<int, Section> keyed by id, in display order
     */
    public function getSections(): array;

    /**
     * The event's places, which a programme names by its placeId (contract 3.1).
     *
     * @return array<int, Place> keyed by id, in the source's order (kissj sends them sorted
     *                          by name); empty from a source that sends none
     */
    public function getPlaces(): array;

    /** The event's programme registration window; either end may be missing. */
    public function getRegistrationWindow(): RegistrationWindow;

    /**
     * The participant the device session is bound to, with their active registrations.
     *
     * @throws SessionExpiredException when the session is unknown, expired or revoked
     * @throws ProgramUnavailableException|ProgramDataException when the provider fails
     */
    public function getParticipant(string $sessionToken): Participant;

    /**
     * The ids of every participant registered for this programme.
     *
     * @return list<int>
     * @throws ProgramUnavailableException|ProgramDataException when the provider fails
     */
    public function getParticipantIdsForProgramme(int $programmeId): array;

    /**
     * @param string $deviceId 64 lowercase hex, the SHA-256 of eventApp's session id
     * @throws ProgramUnavailableException|ProgramDataException when the provider fails (kissj's 429 included)
     */
    public function startPairing(string $deviceId): Pairing;

    /** @throws ProgramUnavailableException|ProgramDataException when the provider fails */
    public function pollPairing(string $deviceCode): PairingPoll;

    /**
     * @throws RegistrationRefusedException with the contract's code when the source says no
     * @throws SessionExpiredException when the session is unknown, expired or revoked
     * @throws ProgramUnavailableException|ProgramDataException when the provider fails
     */
    public function register(string $sessionToken, int $programmeId): void;

    /**
     * @throws RegistrationRefusedException with the contract's code when the source says no
     * @throws SessionExpiredException when the session is unknown, expired or revoked
     * @throws ProgramUnavailableException|ProgramDataException when the provider fails
     */
    public function unregister(string $sessionToken, int $programmeId): void;

    /**
     * Ends the device session at the source; the token answers as expired from then on.
     *
     * @throws SessionExpiredException when the session already is not valid
     * @throws ProgramUnavailableException|ProgramDataException when the provider fails
     */
    public function revokeSession(string $sessionToken): void;
}
