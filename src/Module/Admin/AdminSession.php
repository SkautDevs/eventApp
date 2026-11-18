<?php

declare(strict_types=1);

namespace App\Module\Admin;

use App\Environment;
use App\EventConfig;
use App\Session;
use Psr\Clock\ClockInterface;

/**
 * The admin page's state in the event's session values. The shared link
 * /admin/notify?token=<ADMIN_TOKEN_<SLUG>> logs the browser in for a day (`admin`,
 * `['since' => int]`) and carries a CSRF token (`csrf`) for the forms. Every rendered send
 * form gets a one-time nonce (`notifyNonces`, the newest fifty), consumed under the
 * session lock by the send it carries, and a send flashes its result (`notifyResult`) for
 * the GET it redirects to.
 */
final class AdminSession
{
    /** How long the shared link keeps a browser logged in: what a phone left on a table can do. */
    public const int LIFETIME = 86400;

    /** How many rendered send forms stay valid at once (several open tabs). */
    private const int NONCES = 50;

    public function __construct(
        private readonly Session $session,
        private readonly Environment $environment,
        private readonly EventConfig $event,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Logs the browser in when $token is the event's ADMIN_TOKEN, and returns whether it did.
     * Re-opening the link (a second tab, the team chat) must not invalidate a form already
     * open elsewhere, so a well-formed CSRF token survives it.
     */
    public function open(string $token): bool
    {
        $expected = $this->token();
        if ($expected === '' || !hash_equals($expected, $token)) {
            return false;
        }
        // a privilege change, so the session ID changes with it (see Authenticator::store())
        $this->session->regenerateId();
        $this->session->set('admin', ['since' => $this->clock->now()->getTimestamp()]);
        $csrf = $this->session->get('csrf');
        $this->session->set('csrf', is_string($csrf) && preg_match('/^[0-9a-f]{32}$/', $csrf) === 1
            ? $csrf
            : bin2hex(random_bytes(16)));

        return true;
    }

    /**
     * Whether this browser opened the link less than LIFETIME seconds ago. Removing the token
     * from the config closes the page for sessions already open.
     */
    public function isOpen(): bool
    {
        if ($this->token() === '') {
            return false;
        }
        $flag = $this->session->get('admin');
        if (!is_array($flag) || !is_int($flag['since'] ?? null)) {
            return false;
        }
        $age = $this->clock->now()->getTimestamp() - $flag['since'];

        return $age >= 0 && $age < self::LIFETIME;
    }

    /** The forms' CSRF token, empty before the first login. */
    public function csrf(): string
    {
        $csrf = $this->session->get('csrf', '');

        return is_string($csrf) ? $csrf : '';
    }

    /** Whether a form's `csrf` field is this session's token. */
    public function csrfMatches(mixed $given): bool
    {
        $expected = $this->session->get('csrf');

        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }

    /**
     * A nonce for one rendered send form. A double tap, or a re-send after a 504 whose first
     * send did complete, must not notify everybody twice. Several stay valid at once (two
     * open tabs); the oldest go after NONCES.
     */
    public function issueNonce(): string
    {
        $nonce = bin2hex(random_bytes(16));
        $this->session->transact(static function (array &$bag) use ($nonce): void {
            $bag['notifyNonces'] = array_slice([...(array) ($bag['notifyNonces'] ?? []), $nonce], -self::NONCES);
        });

        return $nonce;
    }

    /**
     * Uses $nonce up and returns whether it was valid. Checked and removed under the session
     * lock, so two requests carrying the same nonce cannot both get through.
     */
    public function consumeNonce(string $nonce): bool
    {
        return (bool) $this->session->transact(static function (array &$bag) use ($nonce): bool {
            $nonces = (array) ($bag['notifyNonces'] ?? []);
            $at = array_search($nonce, $nonces, true);
            if ($at === false) {
                return false;
            }
            unset($nonces[$at]);
            $bag['notifyNonces'] = array_values($nonces);

            return true;
        });
    }

    /**
     * A send's result, for the GET its redirect lands on. The next message usually comes
     * from the same person, so Podpis is kept with it.
     *
     * @param array{sent: int, removed: int, failed: int, unreached: int|null} $result
     */
    public function flashResult(array $result, string $signature): void
    {
        $this->session->set('notifyResult', ['result' => $result, 'signature' => $signature]);
    }

    /**
     * The flashed result, once: `['result' => …, 'signature' => …]`, or null when there is none.
     *
     * @return array<array-key, mixed>|null
     */
    public function takeResult(): ?array
    {
        $flash = $this->session->get('notifyResult');
        if ($flash !== null) {
            $this->session->delete('notifyResult');
        }

        return is_array($flash) ? $flash : null;
    }

    private function token(): string
    {
        return $this->environment->forEvent($this->event->slug, 'ADMIN_TOKEN');
    }
}
