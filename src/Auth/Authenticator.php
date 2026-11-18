<?php

declare(strict_types=1);

namespace App\Auth;

use App\Session;

/**
 * The paired participant of this browser, kept in the session under `identity`: stored on
 * an approved pairing (the session ID rotated first) and dropped on a logout (cleared,
 * then rotated), or when kissj no longer knows the device session (sessionGone()).
 */
final class Authenticator
{
    private const string SESSION_KEY = 'identity';

    public function __construct(
        private readonly Session $session,
        private readonly PairingState $pairing,
    ) {}

    public function store(Identity $identity): void
    {
        // the identity changes here, so the ID that carries it has to change too —
        // otherwise an ID planted before login is the logged-in session afterwards.
        // Rotated first: the old ID keeps the logged-out state (Session::regenerateId()).
        $this->session->regenerateId();
        $this->session->set(self::SESSION_KEY, $identity->toArray());
    }

    public function logout(): void
    {
        // cleared first, rotated after: the old ID, which a late request may still
        // carry, is left holding the logged-out state too
        $this->session->delete(self::SESSION_KEY);
        $this->session->regenerateId();
    }

    /**
     * kissj no longer knows the reader's device session (its 401): the identity is dropped
     * and Profil says why (PairingState::gone(), which also drops a login toast not shown
     * yet). Any screen whose personal read hits it calls this.
     */
    public function sessionGone(): void
    {
        $this->logout();
        $this->pairing->gone();
    }

    public function isLogged(): bool
    {
        return $this->identity() !== null;
    }

    public function identity(): ?Identity
    {
        $data = $this->session->get(self::SESSION_KEY);

        return is_array($data) ? Identity::fromArray($data) : null;
    }
}
