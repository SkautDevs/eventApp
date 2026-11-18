<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Auth\Authenticator;
use App\EventConfig;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\Programme;
use App\Program\ProgramUnavailableException;
use App\Program\RegistrationRefusedException;
use App\Program\SessionExpiredException;
use App\Telemetry\Collector;
use App\Time\Zone;
use App\Toast\Toasts;

/**
 * One register or unregister from a programme's sheet, for a paired reader
 * (RegisterAction and UnregisterAction check the origin and the pairing first): the
 * provider's answer becomes the session toast, and write() names the programme's deep
 * link, where the redirect lands and its sheet reopens under that toast. A repeat's
 * refusal (`alreadyRegistered` after a register, `notRegistered` after an unregister) does
 * not replace the same write's success toast still waiting unseen
 * (`Toasts::putUnlessPending()`, matched by the `tag` `register:<id>`/`unregister:<id>`
 * under one session lock).
 */
final class RegistrationWriter
{
    /**
     * The toast title for each of kissj's refusal codes (kissj's API contract 3.0); a code
     * not listed here is REFUSED.
     */
    private const array REFUSALS = [
        'notPaid' => 'Přihlašování na programy je možné až po zaplacení.',
        'closed' => 'Přihlašování není otevřené.',
        'notFound' => 'Tenhle program už neexistuje.',
        'notPossible' => 'Na tenhle program se nepřihlašuje, máš ho automaticky.',
        'wrongRole' => 'Tenhle program není určený pro tvoji roli.',
        'alreadyRegistered' => 'Tenhle program už máš.',
        'notRegistered' => 'Tenhle program nemáš zapsaný, není co rušit.',
        'conflict' => 'Koliduje s programem, který už máš ve stejném bloku.',
        'full' => 'Je plno.',
    ];

    /** The toast title for a refusal code REFUSALS does not know, per verb (true: register). */
    private const array REFUSED = [true => 'Zápis se nepodařil.', false => 'Zrušení zápisu se nepodařilo.'];

    /** The toast title when the provider could not be reached or answered nonsense, per verb. */
    private const array WRITE_FAILED = [
        true => 'Zápis se teď nedaří, zkus to prosím později.',
        false => 'Zrušení zápisu se teď nedaří, zkus to prosím později.',
    ];

    /**
     * The refusal that, right after the same write succeeded, is only that write tapped
     * twice: the success toast still waiting in the session is the true answer.
     */
    private const array REPEATED = [true => 'alreadyRegistered', false => 'notRegistered'];

    /** The sheet's line, the toast's second line and the 403 page's message for a reader who is not paired. */
    public const string NOT_PAIRED = 'Pro zápis na programy se přihlas kódem z KissJ';

    public function __construct(
        private readonly ProgramProviderInterface $provider,
        private readonly Authenticator $auth,
        private readonly Toasts $toasts,
        private readonly EventConfig $event,
    ) {}

    /**
     * One register or unregister: the provider's answer becomes the session toast, and the
     * reader goes back to the programme. Only a cross-site form or a reader who is not
     * paired gets a page of its own (403), which the actions render before calling this.
     * Toasts stamps the toast from the clock when it is left, i.e. after the provider has
     * answered.
     *
     * @return string the redirect target, `/<slug>/programy#section-<sectionId>-program-<id>`
     */
    public function write(bool $register, int $programmeId): string
    {
        $auth = $this->auth;
        $identity = $auth->identity()
            ?? throw new \LogicException('A registration write needs a paired reader; the action checks the pairing first.');
        $toasts = $this->toasts;
        $provider = $this->provider;

        // the success toast of this very write, which a repeat of it must not talk over
        $tag = ($register ? 'register:' : 'unregister:') . $programmeId;
        try {
            if ($register) {
                $provider->register($identity->sessionToken, $programmeId);
            } else {
                $provider->unregister($identity->sessionToken, $programmeId);
            }
            $programme = $this->programme($programmeId);
            $name = $programme?->name;
            if ($register) {
                $toasts->put($name === null ? 'Zapsáno' : 'Zapsáno: ' . $name, $programme === null ? null : self::toastLine($programme), $tag);
            } else {
                $toasts->put($name === null ? 'Zápis zrušen' : 'Zápis zrušen: ' . $name, 'Místo jsme uvolnili', $tag);
            }
        } catch (RegistrationRefusedException $e) {
            $title = self::REFUSALS[$e->refusal] ?? self::REFUSED[$register];
            if ($e->refusal === self::REPEATED[$register]) {
                // A double tap: the first request's success waits unsaid in the session, and
                // the second one's "you already have it" would replace it with a refusal.
                $toasts->putUnlessPending($title, null, $tag);
            } else {
                $toasts->put($title, null);
            }
        } catch (SessionExpiredException) {
            // the page the redirect lands on renders logged out, and the toast says why
            $auth->sessionGone();
            $gone = $toasts->gone();
            $toasts->put($gone->title, $gone->line);
        } catch (ProgramUnavailableException|ProgramDataException $e) {
            // LocalStateException included: the local source's state file could not be written
            Collector::collect($e);
            $toasts->put(self::WRITE_FAILED[$register], null);
        }

        $programme = $this->programme($programmeId);
        $sectionId = $programme === null ? 0 : $programme->sectionId;

        return '/' . $this->event->slug . '/programy#section-' . $sectionId . '-program-' . $programmeId;
    }

    /**
     * When and where, as the register toast's second line: "Pá 21. 5. · 9:00–12:30 ·
     * Žlutý les". A programme running past midnight names its start day only; the place
     * only when there is one. A date the programme lacks reads as the epoch, as it always has.
     */
    public static function toastLine(Programme $program): string
    {
        $start = self::orEpoch($program->start);
        $end = self::orEpoch($program->end);
        $day = Times::SHORT_DAY_NAMES[(int) $start->format('N')];
        $line = mb_strtoupper(mb_substr($day, 0, 1)) . mb_substr($day, 1) . ' ' . $start->format('j. n.')
            . ' · ' . $start->format(Times::CLOCK) . '–' . $end->format(Times::CLOCK);
        $location = $program->location === null ? '' : trim($program->location);

        return $location === '' ? $line : $line . ' · ' . $location;
    }

    /** The programme with this id from the provider's list, or null when it is not there or the list cannot be read. */
    private function programme(int $id): ?Programme
    {
        try {
            foreach ($this->provider->getPrograms() as $programme) {
                if ($programme->id === $id) {
                    return $programme;
                }
            }
        } catch (ProgramUnavailableException|ProgramDataException) {
            // the toast then names no programme, and the deep link no section
        }

        return null;
    }

    /** A programme's time, or the Unix epoch in Prague for one it lacks. */
    private static function orEpoch(?\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at instanceof \DateTimeImmutable ? $at : (new \DateTimeImmutable('@0'))->setTimezone(Zone::prague());
    }
}
