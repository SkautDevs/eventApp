<?php

declare(strict_types=1);

namespace App\Module\Map;

use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * Místa (/mapa, and /mapa/plan for the plan's view), the second tab: the event's Google
 * map, its site plan, or both. The screen is ShowAction.
 */
final class MapModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'map';
    }

    public function menuItem(): MenuItem
    {
        return new MenuItem(self::key(), 'Místa', 'map', 'fas fa-map-marked-alt', 20, ['map-plan']);
    }

    public function script(): string
    {
        return 'map/index.js';
    }

    public function stylesheet(): string
    {
        return 'map.css';
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        // One screen, two addresses: the plan has a path of its own, and `screen` names
        // the screen it is a view of (ScreenAliasMiddleware)
        $app->get('/mapa', ShowAction::class)->setName('map')
            ->setArgument('view', 'google');
        $app->get('/mapa/plan', ShowAction::class)->setName('map-plan')
            ->setArgument('view', 'plan')->setArgument('screen', '/mapa');
    }
}
