<?php

declare(strict_types=1);

namespace App\Module;

use Slim\App;

/**
 * A module of an event's app. Modules are built for every request, so a constructor takes
 * only what costs nothing to build (the clock, the environment, the client address): a
 * repository or the programme provider there would make every page open SQLite.
 */
interface ModuleInterface
{
    public static function key(): string;

    /** A tab in the bottom bar, or null for a module without one. */
    public function menuItem(): ?MenuItem;

    /**
     * The module's script entry, relative to www/js (`<dir>/index.js`), or null for a module
     * without one. The layout loads the entries in module order (Modules::scripts()).
     */
    public function script(): ?string;

    /**
     * The module's stylesheet, relative to www/css (`<name>.css`), or null for a module
     * without one. The layout links the sheets in module order (Modules::stylesheets()),
     * between css/base.css and css/motion.css.
     */
    public function stylesheet(): ?string;

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void;
}
