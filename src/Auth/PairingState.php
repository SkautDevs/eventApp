<?php

declare(strict_types=1);

namespace App\Auth;

use App\Program\Pairing;
use App\Session;
use App\Toast\Toasts;
use Psr\Clock\ClockInterface;

/**
 * This browser's device pairing as the session keeps it: the pending pairing a start left
 * under `pairing`, and how the last one ended badly.
 *
 * A start, poll or session that went wrong leaves `pairingOutcome` (`{code, at}`) in the
 * session, which a logged-out Profil shows for ten minutes as a `.notice`: `expired`
 * `Kód vypršel, začni znovu.`, `gone` `Propojení s KissJ už neplatí, přihlas se znovu.`,
 * `busy` (the throttle) `Teď se propojuje moc lidí najednou, zkus to za chvíli.`,
 * `unavailable` `Propojení se teď nedaří, zkus to prosím později.`.
 *
 * The stored shapes are the previous release's and must stay readable across a deploy:
 * `pairing` is `{deviceCode, userCode, verificationUrl, expiresAt}` (four non-empty
 * strings, `expiresAt` in DATE_ATOM) and `pairingOutcome` is `{code, at}` (`at` a Unix
 * timestamp, read from the clock when the outcome is left, i.e. after the provider's answer).
 */
final class PairingState
{
    /**
     * The line a logged-out Profil shows after a pairing ended without a login, or a start
     * or a session went wrong, by the code in the session's pairingOutcome.
     *
     * @var array<string, string>
     */
    public const array LINES = [
        'expired' => 'Kód vypršel, začni znovu.',
        'gone' => 'Propojení s KissJ už neplatí, přihlas se znovu.',
        'busy' => 'Teď se propojuje moc lidí najednou, zkus to za chvíli.',
        'unavailable' => 'Propojení se teď nedaří, zkus to prosím později.',
    ];

    /**
     * How long an outcome line stays on Profil. Reading it consumes nothing — the loader's
     * warm-up and the worker's refill read Profil too, and would swallow it — so it ends
     * with the next start, an approval, or this.
     */
    private const int OUTCOME_SECONDS = 600;

    private const string PAIRING_KEY = 'pairing';

    private const string OUTCOME_KEY = 'pairingOutcome';

    public function __construct(
        private readonly Session $session,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * The pending pairing kept in the session by the start, or null when there is none (or
     * it is not one: an older shape, a hand-edited file).
     */
    public function pending(): ?Pairing
    {
        $data = $this->session->get(self::PAIRING_KEY);
        if (!is_array($data)) {
            return null;
        }
        foreach (['deviceCode', 'userCode', 'verificationUrl', 'expiresAt'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                return null;
            }
        }
        $expiresAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['expiresAt']);
        if ($expiresAt === false) {
            return null;
        }

        return new Pairing($data['deviceCode'], $data['userCode'], $data['verificationUrl'], $expiresAt, 5);
    }

    /** Stores a started pairing and drops any outcome before it, in one write. */
    public function begin(Pairing $pairing): void
    {
        $this->session->transact(static function (array &$bag) use ($pairing): void {
            unset($bag[self::OUTCOME_KEY]);
            $bag[self::PAIRING_KEY] = [
                'deviceCode' => $pairing->deviceCode,
                'userCode' => $pairing->userCode,
                'verificationUrl' => $pairing->verificationUrl,
                'expiresAt' => $pairing->expiresAt->format(\DATE_ATOM),
            ];
        });
    }

    /** Ends the pending pairing with the outcome `$code` (a key of LINES), in one write. */
    public function fail(string $code): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->session->transact(static function (array &$bag) use ($code, $now): void {
            unset($bag[self::PAIRING_KEY]);
            $bag[self::OUTCOME_KEY] = ['code' => $code, 'at' => $now];
        });
    }

    /**
     * Drops the pairing and any outcome: the reader is in now. Called before the identity
     * is stored and the ID rotates, so the old ID holds neither the pairing nor the identity
     * and a late poll under it cannot collect a re-issued approval.
     */
    public function approved(): void
    {
        $this->session->transact(static function (array &$bag): void {
            unset($bag[self::PAIRING_KEY], $bag[self::OUTCOME_KEY]);
        });
    }

    /**
     * Drops the pairing only (the logout). Written unconditionally, as the logout always
     * wrote its session, whether or not a pairing was pending.
     */
    public function drop(): void
    {
        $this->session->transact(static function (array &$bag): void {
            unset($bag[self::PAIRING_KEY]);
        });
    }

    /**
     * kissj no longer knows the reader's device session: Profil says so, and a login toast
     * not shown yet, which would now be a lie, goes. One write.
     */
    public function gone(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->session->transact(static function (array &$bag) use ($now): void {
            $bag[self::OUTCOME_KEY] = ['code' => 'gone', 'at' => $now];
            unset($bag[Toasts::SESSION_KEY]);
        });
    }

    /** The outcome line Profil shows now, or null: none set, an unknown code, or ten minutes old. */
    public function outcomeLine(): ?string
    {
        $outcome = $this->session->get(self::OUTCOME_KEY);
        if (!is_array($outcome) || !is_string($outcome['code'] ?? null) || !is_int($outcome['at'] ?? null)) {
            return null;
        }
        if ($this->clock->now()->getTimestamp() - $outcome['at'] >= self::OUTCOME_SECONDS) {
            return null;
        }

        return self::LINES[$outcome['code']] ?? null;
    }
}
