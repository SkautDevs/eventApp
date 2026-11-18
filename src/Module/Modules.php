<?php

declare(strict_types=1);

namespace App\Module;

use App\EventConfig;
use Psr\Container\ContainerInterface;

/**
 * The modules of this app's event, the core ones included, in ModuleRegistry::ORDER, built
 * once: each is taken from the container on the first call of all(), and the same instances
 * are handed out after. Kernel registers their routes from it, and the tab bar (Domů first)
 * and the service worker's precache list read their tabs through menu(), the layout
 * its script entries through scripts(), and both the layout and the precache list its
 * stylesheets through sheets().
 */
final class Modules
{
    /** @var list<ModuleInterface>|null */
    private ?array $modules = null;

    public function __construct(private readonly ContainerInterface $container, private readonly EventConfig $event) {}

    /** @return list<ModuleInterface> */
    public function all(): array
    {
        if ($this->modules === null) {
            $modules = [];
            foreach (ModuleRegistry::modulesFor($this->event) as $class) {
                $module = $this->container->get($class);
                \assert($module instanceof ModuleInterface);
                $modules[] = $module;
            }
            $this->modules = $modules;
        }

        return $this->modules;
    }

    /**
     * Every tab, by its `order`: the modules' menu items, and none for a module without one.
     *
     * @return list<MenuItem>
     */
    public function menu(): array
    {
        $menu = [];
        foreach ($this->all() as $module) {
            if (($item = $module->menuItem()) !== null) {
                $menu[] = $item;
            }
        }
        usort($menu, static fn(MenuItem $a, MenuItem $b): int => $a->order <=> $b->order);

        return $menu;
    }

    /**
     * Every module's script entry, relative to www/js, in module order (ModuleRegistry::ORDER):
     * the order the layout loads them in, so the loader starts before any screen's script.
     *
     * @return list<string>
     */
    public function scripts(): array
    {
        $scripts = [];
        foreach ($this->all() as $module) {
            if (($script = $module->script()) !== null) {
                $scripts[] = $script;
            }
        }

        return $scripts;
    }

    /**
     * Every module's stylesheet, as a path under www/ (`css/<name>.css`), in module order
     * (ModuleRegistry::ORDER): a sheet is linked only for a module the event registers.
     *
     * @return list<string>
     */
    public function stylesheets(): array
    {
        $sheets = [];
        foreach ($this->all() as $module) {
            if (($sheet = $module->stylesheet()) !== null) {
                $sheets[] = 'css/' . $sheet;
            }
        }

        return $sheets;
    }

    /**
     * The core sheets in the order the layout links them and the precache list names them:
     * css/base.css (the faces, the tokens and roles, the bare elements), the modules' own
     * (stylesheets()), then css/motion.css, which comes last so its rules win. The event's
     * own sheet, if it has one, follows all of them.
     *
     * @return list<string>
     */
    public function sheets(): array
    {
        return ['css/base.css', ...$this->stylesheets(), 'css/motion.css'];
    }
}
