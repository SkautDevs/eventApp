<?php

declare(strict_types=1);

namespace App;

final class Session
{
    private bool $started = false;

    /**
     * One cookie serves every event on the host, so each event's values live under its
     * own key — a TIE code means nothing outside its event, and neither does a logout.
     */
    public function __construct(private readonly string $namespace = '')
    {
    }

    /**
     * Read-only view that leaves no empty bag behind for an event that never wrote.
     *
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $this->start();

        return $this->namespace === '' ? $_SESSION : ($_SESSION[$this->namespace] ?? []);
    }

    /** @return array<string, mixed> */
    private function &bag(): array
    {
        $this->start();
        if ($this->namespace === '') {
            return $_SESSION;
        }
        $_SESSION[$this->namespace] ??= [];

        return $_SESSION[$this->namespace];
    }

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
        return $this->read()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $bag = &$this->bag();
        $bag[$key] = $value;
    }

    public function delete(string $key): void
    {
        if (array_key_exists($key, $this->read())) {
            $bag = &$this->bag();
            unset($bag[$key]);
        }
    }

    public function has(string $key): bool
    {
        return isset($this->read()[$key]);
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
