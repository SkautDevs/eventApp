<?php

declare(strict_types=1);

namespace App\Auth;

use App\Session;

final class Authenticator
{
    private const string SESSION_KEY = 'identity';

    public function __construct(private readonly Session $session)
    {
    }

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
