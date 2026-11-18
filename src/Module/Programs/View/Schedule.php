<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

/**
 * The Program screen's schedule (ScheduleBuilder::buildViewModel()): the timeline's pages
 * and the one it opens on, Přehled's days and Moje's with the day each strip opens on, and
 * every programme's sheet (messages empty, footer null until ShowAction completes them).
 */
final readonly class Schedule
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
    ) {}
}
