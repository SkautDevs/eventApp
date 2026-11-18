<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

use App\Toast\Toast;

/**
 * Everything templates/programs.twig is rendered with, one property per template variable:
 * the schedule's (Schedule) with every sheet completed, the notice of what could not be
 * read, whether the programme list could not be read at all, whether the reader is paired
 * and whether their own list was read, and the toast a register or unregister left.
 * ShowAction renders get_object_vars() of it. The lists stay PHP lists: the template asks
 * whether they are empty.
 */
final readonly class ProgramsPage
{
    /**
     * @param list<TimelinePage> $pages
     * @param list<Day> $overview
     * @param list<Day> $days
     * @param list<Sheet> $details
     */
    public function __construct(
        public array $pages,
        public ?string $activePage,
        public array $overview,
        public ?string $activeOverview,
        public array $days,
        public ?string $activeDay,
        public array $details,
        public ?string $notice,
        public bool $programmesUnavailable,
        public bool $isLogged,
        public bool $mineRead,
        public ?Toast $toast,
    ) {}
}
