<?php

declare(strict_types=1);

namespace App\Toast;

use App\Auth\PairingState;
use App\Session;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The server's toasts, left by what caused them (a login, a logout, a register or
 * unregister); the shell's own offline toast is www/js/shell/toast.js's.
 *
 * The server's ones wait in the session under `toast` (`['title', 'line', 'at' => <the clock's timestamp>]`,
 * plus a `tag` for a write's success): put() leaves one, take() takes it on the next full
 * document the reader asks for — never a fragment (`X-Screen`, which the loader's warm-up
 * sends) nor the worker's own fill and refill fetches (`X-Worker-Fill: 1`,
 * WORKER_FILL_HEADER), which would swallow it before the reader sees anything — and drops
 * one older than 60 s (SECONDS) unsaid. A request with no toast pending never opens the
 * session.
 *
 * The stored shape is the previous release's and must stay readable across a deploy:
 * `['title' => string, 'line' => ?string, 'at' => int]`, plus the `tag` of the write it
 * reports (putUnlessPending()). `at` is a Unix timestamp read from the clock when the toast
 * is left, i.e. after whatever the provider answered.
 *
 * A response carrying a toast goes through mark(), which sets `X-Toast: 1` (HEADER), and
 * the worker never stores such a response, so the offline copy is fetched again without it.
 */
final class Toasts
{
    /**
     * The response header of a page or fragment that carries a toast (mark()). The worker
     * never stores such a response (www/sw.js), or its offline copy would say the toast again.
     */
    public const string HEADER = 'X-Toast';

    /**
     * The request header of the worker's own fetches of a whole document, to fill or refill
     * the offline copy (www/sw.js storeBoth()). Such a request is not the reader looking, so
     * it takes no toast (take()).
     */
    public const string WORKER_FILL_HEADER = 'X-Worker-Fill';

    /** The session key of the one pending server toast (take()). */
    public const string SESSION_KEY = 'toast';

    /**
     * How long a pending toast waits for its page. It is said right after what caused it (a
     * login, a logout); one still in the session a minute later would greet the reader with
     * news about something long past.
     */
    private const int SECONDS = 60;

    /** The title on the first full Profil after a pairing was approved. */
    private const string LOGGED_IN = 'Jsi přihlášen';

    /** The title and line on the first full Profil after a logout. */
    private const string LOGGED_OUT = 'Odhlášeno';

    private const string LOGGED_OUT_LINE = 'Obsah teď můžeš jen prohlížet';

    public function __construct(
        private readonly Session $session,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Leaves a toast in the session for the next full document the reader asks for
     * (take()): what a write said, shown on the page its redirect lands on.
     */
    public function put(string $title, ?string $line, ?string $tag = null): void
    {
        $this->session->set(self::SESSION_KEY, self::entry($title, $line, $tag, $this->clock->now()->getTimestamp()));
    }

    /**
     * put(), unless the toast still waiting in the session carries `$tag` and is not yet
     * too old to be shown: that one stays. A write's success tags its toast, and the same
     * write sent twice (a double tap) is refused the second time; the refusal must not talk
     * over the success the reader has not seen yet. Checked and written under one lock.
     */
    public function putUnlessPending(string $title, ?string $line, string $tag): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->session->transact(static function (array &$bag) use ($title, $line, $tag, $now): void {
            $pending = $bag[self::SESSION_KEY] ?? null;
            if (is_array($pending) && ($pending['tag'] ?? null) === $tag
                && is_int($pending['at'] ?? null) && $now - $pending['at'] <= self::SECONDS
            ) {
                return;
            }
            $bag[self::SESSION_KEY] = self::entry($title, $line, null, $now);
        });
    }

    /** The toast on the first full Profil after a pairing was approved. */
    public function loggedIn(): void
    {
        $this->put(self::LOGGED_IN, null);
    }

    /** The toast on the first full Profil after a logout. */
    public function loggedOut(): void
    {
        $this->put(self::LOGGED_OUT, self::LOGGED_OUT_LINE);
    }

    /**
     * The toast of a page that found the session gone while it rendered
     * (Authenticator::sessionGone()): that page renders logged out, so it says why itself.
     */
    public function gone(): Toast
    {
        return new Toast(self::LOGGED_OUT, PairingState::LINES['gone']);
    }

    /**
     * The pending toast taken out of the session, or null. Only a full document the reader
     * asked for takes it. A fragment (X-Screen) leaves it, because the loader's warm-up
     * reads every screen as a fragment; so does the worker's own fetch of a whole document
     * (WORKER_FILL_HEADER), because its fill and refill read every screen as a page and as
     * a fragment and would swallow it before the reader sees anything. One older than a
     * minute is dropped unsaid. A request without one never opens the session, so a
     * logged-out reader gets no session from it.
     */
    public function take(ServerRequestInterface $request): ?Toast
    {
        if ($request->getHeaderLine('X-Screen') === '1'
            || $request->getHeaderLine(self::WORKER_FILL_HEADER) === '1'
            || !$this->session->has(self::SESSION_KEY)
        ) {
            return null;
        }
        $toast = $this->session->transact(static function (array &$bag): mixed {
            $toast = $bag[self::SESSION_KEY] ?? null;
            unset($bag[self::SESSION_KEY]);

            return $toast;
        });
        if (!is_array($toast) || !is_string($toast['title'] ?? null) || $toast['title'] === '' || !is_int($toast['at'] ?? null)) {
            return null;
        }
        if ($this->clock->now()->getTimestamp() - $toast['at'] > self::SECONDS) {
            return null;
        }
        $line = $toast['line'] ?? null;

        return new Toast($toast['title'], is_string($line) && $line !== '' ? $line : null);
    }

    /**
     * Marks a response that carries a toast (HEADER), so the worker keeps it out of the
     * offline copy; a response without one is returned as it is.
     */
    public function mark(ResponseInterface $response, ?Toast $toast): ResponseInterface
    {
        return $toast === null ? $response : $response->withHeader(self::HEADER, '1');
    }

    /** @return array{title: string, line: ?string, at: int, tag?: string} */
    private static function entry(string $title, ?string $line, ?string $tag, int $now): array
    {
        $entry = ['title' => $title, 'line' => $line, 'at' => $now];
        if ($tag !== null) {
            $entry['tag'] = $tag;
        }

        return $entry;
    }
}
