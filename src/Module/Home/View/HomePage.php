<?php

declare(strict_types=1);

namespace App\Module\Home\View;

use App\Event\Link;
use App\Toast\Toast;

/**
 * Everything templates/homepage.twig is rendered with, one property per template variable:
 * the event's quick links (`content/links.php`, of which Domů shows the `highlight` ones;
 * `[]` for an event without the file), the screen's view model, and the toast that
 * says why this very page renders logged out. ShowAction renders get_object_vars() of it.
 */
final readonly class HomePage
{
    /** @param list<Link> $links */
    public function __construct(
        public array $links,
        public HomeView $home,
        public ?Toast $toast,
    ) {}
}
