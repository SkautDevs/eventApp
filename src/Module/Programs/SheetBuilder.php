<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Module\Programs\View\Sheet;
use App\Module\Programs\View\SheetFooter;
use App\Program\Programme;
use App\Program\RegistrationWindow;
use App\Program\Section;
use App\Time\Zone;

/**
 * A programme's sheet, the one dialog of the Program screen: its payload (buildDetail(),
 * one per programme id, for every view and the hash deep link) and what its footer offers
 * (footer()). Pure.
 */
final class SheetBuilder
{
    /**
     * The sheet prints the location as given; only the cards and the list rows trim it.
     *
     * @param array<int, Section> $sections
     * @param array<int, string> $pageOfProgram
     * @param array<int, true> $registeredIds
     */
    public static function buildDetail(Programme $program, array $sections, array $pageOfProgram, array $registeredIds): Sheet
    {
        $section = $sections[$program->sectionId] ?? null;

        return new Sheet(
            id: $program->id,
            name: $program->name,
            when: Times::whenLabel($program),
            location: $program->location,
            perex: $program->perex,
            lector: $program->lector,
            tools: $program->tools,
            section: $section === null ? null : self::sectionTitle($section),
            page: $pageOfProgram[$program->id] ?? null,
            sectionId: $program->sectionId,
            registered: isset($registeredIds[$program->id]),
            // kissj's isAutoenrolled: fixed for its roles, never in a participant's own list
            preregistered: $program->preregistered,
            images: $program->images,
            placeId: $program->placeId,
        );
    }

    /**
     * What a sheet's footer offers, in the order the spec's bullets imply: not paired
     * first; then a window that is not open, by why; then, paired and open, nothing for a
     * preregistered programme, else register or unregister. Null when the participant's
     * own list could not be read: "register" would be a guess.
     *
     * Only the sheet's `preregistered` and `registered` are read.
     */
    public static function footer(Sheet $detail, bool $paired, bool $mineRead, RegistrationWindow $window, \DateTimeImmutable $now): ?SheetFooter
    {
        if (!$paired) {
            return new SheetFooter('unpaired', null);
        }
        $state = $window->state($now);
        if ($state === 'before' && $window->start !== null) {
            $start = $window->start->setTimezone(Zone::prague());

            return new SheetFooter('before', 'Přihlašování začne ' . $start->format('j. n. Y \\v ' . Times::CLOCK));
        }
        if ($state === 'after') {
            return new SheetFooter('after', 'Přihlašování skončilo');
        }
        if ($state !== 'open') {
            return new SheetFooter('none', 'Přihlašování zatím není otevřené');
        }
        if ($detail->preregistered) {
            return new SheetFooter('fixed', null);
        }
        if (!$mineRead) {
            return null;
        }

        return new SheetFooter($detail->registered ? 'unregister' : 'register', null);
    }

    private static function sectionTitle(Section $section): string
    {
        return $section->subTitle !== null ? $section->title . ' – ' . $section->subTitle : $section->title;
    }
}
