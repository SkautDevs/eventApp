<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * A paired participant as a template sees them — `auth_identity()` in the app bar and the
 * push-identity meta, and Profil's heading: kissj's participant id, the display name
 * (the nickname or `Účastník`) and the initials (null for the generic person icon). The
 * device session token is never in it: no template ever gets an Identity.
 */
final readonly class IdentityView
{
    public function __construct(
        public int $participantId,
        public string $displayName,
        public ?string $initials,
    ) {}

    public static function of(Identity $identity): self
    {
        return new self($identity->participantId, $identity->displayName(), $identity->initials());
    }
}
