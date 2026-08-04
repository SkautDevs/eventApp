<?php

declare(strict_types=1);

namespace App\Module;

use Slim\App;

interface ModuleInterface
{
    public static function key(): string;

    /** @return array{label: string, route: string}|null null = no menu entry */
    public function menuItem(): ?array;

    public function registerRoutes(App $app): void;
}
