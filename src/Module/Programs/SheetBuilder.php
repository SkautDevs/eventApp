<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Program\RegistrationWindow;
use App\Time\Zone;

/**
 * A programme's sheet, the one dialog of the Program screen: its payload (buildDetail(),
 * one per programme id, for every view and the hash deep link) and what its footer offers
 * (footer()). Pure.
 */
final class SheetBuilder
{
    /**
     * @param array<int, array> $sections
     * @param array<int, string> $pageOfProgram
     * @param array<int, true> $registeredIds
     */
    public static function buildDetail(array $program, array $sections, array $pageOfProgram, array $registeredIds): array
    {
        $section = $sections[$program['section']['id'] ?? -1] ?? null;

        return [
            'id' => $program['id'],
            'name' => $program['name'],
            'when' => Times::whenLabel($program),
            'location' => $program['location'] ?? null,
            'perex' => $program['perex'] ?? null,
            'lector' => $program['lector'] ?? null,
            'tools' => $program['tools'] ?? null,
            'section' => $section === null ? null : self::sectionTitle($section),
            'page' => $pageOfProgram[$program['id']] ?? null,
            'registered' => isset($registeredIds[$program['id']]),
            'preregistered' => self::preregistered($program),
        ];
    }

    /**
     * What a sheet's footer offers, in the order the spec's bullets imply: not paired
     * first; then a window that is not open, by why; then, paired and open, nothing for a
     * preregistered programme, else register or unregister. Null when the participant's
     * own list could not be read: "register" would be a guess.
     *
     * @param array{preregistered: bool, registered: bool} $detail
     * @return array{state: string, line: ?string}|null
     */
    public static function footer(array $detail, bool $paired, bool $mineRead, RegistrationWindow $window, \DateTimeImmutable $now): ?array
    {
        if (!$paired) {
            return ['state' => 'unpaired', 'line' => null];
        }
        $state = $window->state($now);
        if ($state === 'before' && $window->start !== null) {
            $start = $window->start->setTimezone(Zone::prague());

            return ['state' => 'before', 'line' => 'Přihlašování začne ' . $start->format('j. n. Y \\v ' . Times::CLOCK)];
        }
        if ($state === 'after') {
            return ['state' => 'after', 'line' => 'Přihlašování skončilo'];
        }
        if ($state !== 'open') {
            return ['state' => 'none', 'line' => 'Přihlašování zatím není otevřené'];
        }
        if ($detail['preregistered']) {
            return ['state' => 'fixed', 'line' => null];
        }
        if (!$mineRead) {
            return null;
        }

        return ['state' => $detail['registered'] ? 'unregister' : 'register', 'line' => null];
    }

    /** kissj's isPreregistered: fixed for its roles, never in a participant's own list. */
    public static function preregistered(array $program): bool
    {
        return ($program['preregistered'] ?? false) === true;
    }

    private static function sectionTitle(array $section): string
    {
        return isset($section['subTitle']) ? $section['title'] . ' – ' . $section['subTitle'] : $section['title'];
    }
}
