<?php

declare(strict_types=1);

namespace App\Module\Shell;

use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * The installed app's own routes, part of every event: the offline page and the service
 * worker's precache list. No tab.
 */
final class ShellModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'shell';
    }

    public function menuItem(): ?MenuItem
    {
        return null;
    }

    public function script(): string
    {
        return 'shell/index.js';
    }

    public function stylesheet(): string
    {
        return 'shell.css';
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        // The worker's last fallback for a page that is neither cached nor reachable. It is
        // in the precache list, so every installed worker holds it.
        $app->get('/offline', OfflineAction::class)->setName('offline');

        // What the service worker keeps (www/sw.js install): the screens, the content-hashed
        // assets, the optional handbook. A path with a dot: bin/router.php gets it past `php -S`.
        $app->get('/precache.json', PrecacheAction::class)->setName('precache');
    }
}
