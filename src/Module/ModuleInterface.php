<?php

declare(strict_types=1);

namespace App\Module;

use Slim\App;

interface ModuleInterface
{
    public static function key(): string;

    /**
     * A tab in the bottom bar. `icon` is a FontAwesome class, `order` sorts the bar
     * independently of the order features happen to be listed in the event config.
     *
     * @return array{label: string, route: string, icon: string, order: int}|null null = no tab
     */
    public function menuItem(): ?array;

    public function registerRoutes(App $app): void;
}
