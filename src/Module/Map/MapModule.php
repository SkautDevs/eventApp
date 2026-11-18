<?php

declare(strict_types=1);

namespace App\Module\Map;

use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/** Místa (/mapa), the second tab: the event's Google map, its site plan, or both. The screen is ShowAction. */
final class MapModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'map';
    }

    public function menuItem(): MenuItem
    {
        return new MenuItem(self::key(), 'Místa', 'map', 'fas fa-map-marked-alt', 20);
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        $app->get('/mapa', ShowAction::class)->setName('map');
    }
}
