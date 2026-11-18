<?php

declare(strict_types=1);

namespace App\Auth;

use App\Program\Participant;

/**
 * What CurrentParticipant::read() found, as one of four outcomes, each built by its own
 * named constructor so no other combination can exist: none() for a reader who is not
 * paired, read() with the participant, gone() when kissj answered that the device session
 * is no longer valid (the reader was logged out just now), and unavailable() when the
 * provider failed or answered nonsense. `participant` is non-null for read() only, and at
 * most one flag is true. Whether the reader is logged in is asked of
 * Authenticator::isLogged() after the read, not kept here.
 */
final readonly class ParticipantRead
{
    private function __construct(
        public ?Participant $participant,
        public bool $gone,
        public bool $unavailable,
    ) {}

    /** The reader is not paired: nothing was asked. */
    public static function none(): self
    {
        return new self(null, false, false);
    }

    public static function read(Participant $participant): self
    {
        return new self($participant, false, false);
    }

    /** kissj no longer knows the device session. */
    public static function gone(): self
    {
        return new self(null, true, false);
    }

    /** The provider failed or answered nonsense. */
    public static function unavailable(): self
    {
        return new self(null, false, true);
    }
}
