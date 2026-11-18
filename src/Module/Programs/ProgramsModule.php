<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * The single Program screen (/programy).
 *
 * The screen renders three views into one page and switches between them client side:
 * Přehled, a list of everything by day; Harmonogram, a Gantt-style timeline of
 * everything; and Moje, the same list of the programmes the logged-in participant is
 * registered for. All grouping happens in PHP (ScheduleBuilder and the builders it
 * calls) — the template only walks an already-shaped view model. The screen is
 * ShowAction; the sheet's register and unregister forms are RegisterAction and
 * UnregisterAction.
 */
final class ProgramsModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'programs';
    }

    public function menuItem(): MenuItem
    {
        return new MenuItem(self::key(), 'Program', 'programs', 'far fa-calendar-alt', 10);
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        $app->get('/programy', ShowAction::class)->setName('programs');

        // Registration from the sheet: a plain form POST, so the worker's purge-on-write and
        // the loader's "every non-GET is a navigation" keep every screen consistent. The
        // outcome waits in the session as a toast, and the redirect lands on the
        // programme's deep link, which reopens its sheet under that toast.
        $app->post('/programy/{id:[0-9]+}/prihlasit', RegisterAction::class)->setName('program-register');
        $app->post('/programy/{id:[0-9]+}/odhlasit', UnregisterAction::class)->setName('program-unregister');
    }
}
