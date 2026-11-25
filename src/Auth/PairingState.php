<?php

declare(strict_types=1);

namespace App\Auth;

use App\Program\Pairing;
use App\Program\Programme;
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
 * While a pairing is pending, a no-JS check (`POST /profil/kontrola`) that found it still
 * waiting leaves `pairingChecked` (a Unix timestamp), and for a minute Profil's status
 * line reads NOT_YET instead of WAITING. Reading it consumes nothing, as with the outcome.
 *
 * A start sent from a programme's sheet (`program` in the form) leaves `pairingReturn`
 * beside the pairing: the programme and its section, read from the provider's list at the
 * start, so the approval lands on that sheet (returnTarget()) without asking the provider
 * again. Every end of the pairing drops it: a failure, the approval, the logout, and a start
 * without one.
 *
 * The stored shapes are the previous release's and must stay readable across a deploy:
 * `pairing` is `{deviceCode, userCode, verificationUrl, expiresAt}` (four non-empty
 * strings, `expiresAt` in DATE_ATOM) and `pairingOutcome` is `{code, at}` (`at` a Unix
 * timestamp, read from the clock when the outcome is left, i.e. after the provider's answer).
 * `pairingReturn` is new in this release: `{program, section}`, the programme's id (a
 * positive int) and its section's (an int, 0 for none). A malformed one is no target.
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

    /** Profil's status line while the pairing waits for the approval in KissJ. */
    public const string WAITING = 'Čekám na potvrzení v KissJ…';

    /** The status line for a minute after a check found the pairing still waiting. */
    public const string NOT_YET = 'Zatím nepotvrzeno, zkus to za chvilku.';

    /** How long NOT_YET stays after a check. */
    private const int CHECK_SECONDS = 60;

    private const string CHECK_KEY = 'pairingChecked';

    private const string PAIRING_KEY = 'pairing';

    private const string OUTCOME_KEY = 'pairingOutcome';

    private const string RETURN_KEY = 'pairingReturn';

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

    /**
     * The programme id a form or a query sent (`program`): a positive decimal of at most 10
     * digits, nothing before or after it, else null. Whether the provider lists it is the
     * caller's to check.
     */
    public static function programId(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,9}\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Stores a started pairing and drops any outcome before it, in one write, with the
     * programme whose sheet the reader returns to after the approval (pairingReturn), or
     * without one, which drops an earlier start's.
     */
    public function begin(Pairing $pairing, ?Programme $returnTo = null): void
    {
        $this->session->transact(static function (array &$bag) use ($pairing, $returnTo): void {
            unset($bag[self::OUTCOME_KEY], $bag[self::CHECK_KEY]);
            $bag[self::PAIRING_KEY] = [
                'deviceCode' => $pairing->deviceCode,
                'userCode' => $pairing->userCode,
                'verificationUrl' => $pairing->verificationUrl,
                'expiresAt' => $pairing->expiresAt->format(\DATE_ATOM),
            ];
            if ($returnTo === null) {
                unset($bag[self::RETURN_KEY]);
            } else {
                $bag[self::RETURN_KEY] = ['program' => $returnTo->id, 'section' => $returnTo->sectionId];
            }
        });
    }

    /** Ends the pending pairing with the outcome `$code` (a key of LINES), in one write. */
    public function fail(string $code): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->session->transact(static function (array &$bag) use ($code, $now): void {
            unset($bag[self::PAIRING_KEY], $bag[self::CHECK_KEY], $bag[self::RETURN_KEY]);
            $bag[self::OUTCOME_KEY] = ['code' => $code, 'at' => $now];
        });
    }

    /**
     * Drops the pairing, its return target and any outcome: the reader is in now. Called
     * before the identity is stored and the ID rotates, so the old ID holds neither the
     * pairing nor the identity and a late poll under it cannot collect a re-issued approval.
     * The landing reads returnTarget() before this.
     */
    public function approved(): void
    {
        $this->session->transact(static function (array &$bag): void {
            unset($bag[self::PAIRING_KEY], $bag[self::OUTCOME_KEY], $bag[self::CHECK_KEY], $bag[self::RETURN_KEY]);
        });
    }

    /**
     * Drops the pairing (with its last check and its return target) only: the logout.
     * Written unconditionally, as the logout always wrote its session, whether or not a
     * pairing was pending.
     */
    public function drop(): void
    {
        $this->session->transact(static function (array &$bag): void {
            unset($bag[self::PAIRING_KEY], $bag[self::CHECK_KEY], $bag[self::RETURN_KEY]);
        });
    }

    /**
     * The programme id the pending pairing returns to, or null: for the `program` field of
     * Profil's `Začít znovu` form, so a restart keeps the target.
     */
    public function returnProgram(): ?int
    {
        $program = $this->returnEntry()['program'] ?? null;

        return is_int($program) && $program > 0 ? $program : null;
    }

    /**
     * Where the approval lands for the pending pairing's programme, relative to the event:
     * `programy#section-<s>-program-<id>`, its sheet on Harmonogram; null when there is
     * none or it is malformed.
     */
    public function returnTarget(): ?string
    {
        $entry = $this->returnEntry();
        $program = $entry['program'] ?? null;
        $section = $entry['section'] ?? null;
        if (!is_int($program) || $program <= 0 || !is_int($section) || $section < 0) {
            return null;
        }

        return sprintf('programy#section-%d-program-%d', $section, $program);
    }

    /** A no-JS check found the pairing still waiting: the clock's now, for waitingLine(). */
    public function checked(): void
    {
        $this->session->set(self::CHECK_KEY, $this->clock->now()->getTimestamp());
    }

    /** Profil's status line under the pending code: NOT_YET within a minute of a check, else WAITING. */
    public function waitingLine(): string
    {
        $at = $this->session->get(self::CHECK_KEY);
        if (is_int($at) && $this->clock->now()->getTimestamp() - $at < self::CHECK_SECONDS) {
            return self::NOT_YET;
        }

        return self::WAITING;
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

    /** @return array<mixed> the stored pairingReturn, or an empty array when it is not one */
    private function returnEntry(): array
    {
        $entry = $this->session->get(self::RETURN_KEY);

        return is_array($entry) ? $entry : [];
    }
}
