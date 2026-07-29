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
            session_set_cookie_params([
                'lifetime' => 7 * 24 * 3600,
                'httponly' => true,
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
}
