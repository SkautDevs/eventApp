<?php

declare(strict_types=1);

namespace App\Module;

use App\EventConfig;
use Psr\Container\ContainerInterface;

/**
 * The modules of this app's event, the core ones included, in ModuleRegistry::ORDER, built
 * once: each is taken from the container on the first call of all(), and the same instances
 * are handed out after. Kernel registers their routes from it, and the tab bar (Domů first)
 * and the service worker's precache list read their tabs through menu().
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
}
