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
        $this->session->set(self::SESSION_KEY, $identity->toArray());
    }

    public function logout(): void
    {
        $this->session->delete(self::SESSION_KEY);
    }

    public function isLogged(): bool
    {
        return $this->session->has(self::SESSION_KEY);
    }

    public function identity(): ?Identity
    {
        $data = $this->session->get(self::SESSION_KEY);

        return $data === null ? null : Identity::fromArray($data);
    }
}
