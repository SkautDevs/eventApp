<?php

declare(strict_types=1);

namespace App;

final class Session
{
    private bool $started = false;

    private function start(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() !== \PHP_SESSION_ACTIVE && \PHP_SAPI !== 'cli') {
            session_name('eventapp');
            // Without strict mode PHP adopts any session ID the client presents, even one
            // it never issued — which is half of a session-fixation attack. The other half
            // is the missing rotation on login, see Authenticator::store().
            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params([
                'lifetime' => 7 * 24 * 3600,
                'httponly' => true,
                // read off the request rather than hardcoded: the flag is required once the
                // app is on HTTPS, and would make the cookie unusable over plain http in dev
                'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        $_SESSION ??= [];
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function delete(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function has(string $key): bool
    {
        $this->start();

        return isset($_SESSION[$key]);
    }

    /**
     * Issues a fresh session ID and destroys the old one, keeping the session data.
     * Called on every privilege change so a planted or leaked ID stops being the
     * one the logged-in session answers to. A no-op under CLI, where no session
     * is ever started and $_SESSION is a plain array.
     */
    public function regenerateId(): void
    {
        $this->start();
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
