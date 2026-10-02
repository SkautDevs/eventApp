<?php

declare(strict_types=1);

namespace App\Auth;

use App\Session;

final class Authenticator
{
    private const SESSION_KEY = 'identity';

    public function __construct(private readonly Session $session)
    {
    }

    public function store(Identity $identity): void
    {
        // the identity changes here, so the ID that carries it has to change too —
        // otherwise an ID planted before login is the logged-in session afterwards
        $this->session->regenerateId();
        $this->session->set(self::SESSION_KEY, $identity->toArray());
    }

    public function logout(): void
    {
        $this->session->regenerateId();
        $this->session->delete(self::SESSION_KEY);
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
