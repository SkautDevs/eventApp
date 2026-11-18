<?php

declare(strict_types=1);

namespace App\Module\Home\View;

use App\Push\Notification;

/**
 * Domů's view model (HomeModel::build()). `phase` is where the event is on the Prague
 * calendar — `before`, `during` or `after`, null for an event without dates (rendered as
 * during); the countdown is there only before the start, the window card only while the
 * registration window is ahead or open, and `nextUp` only when the participant has a
 * programme to show. `news` is today's notifications.
 */
final readonly class HomeView
{
    /** @param list<Notification> $news */
    public function __construct(
        public ?string $phase,
        public string $todayLabel,
        public ?Countdown $countdown,
        public ?WindowCard $window,
        public bool $paired,
        public array $news,
        public ?NextUpView $nextUp,
    ) {}
}
