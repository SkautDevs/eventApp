<?php

declare(strict_types=1);

namespace App\Module;

use App\EventConfig;
use App\Module\Admin\AdminModule;
use App\Module\Handbook\HandbookModule;
use App\Module\Home\HomeModule;
use App\Module\Inbox\InboxModule;
use App\Module\Info\InfoModule;
use App\Module\Map\MapModule;
use App\Module\Profile\ProfileModule;
use App\Module\Programs\ProgramsModule;
use App\Module\Push\PushModule;
use App\Module\Shell\ShellModule;

/**
 * Which module serves which feature flag of an event's `features`, which modules every
 * event has (CORE), and the one order the modules are taken in: the order their routes
 * are registered in.
 */
final class ModuleRegistry
{
    /**
     * Registration order, script order and stylesheet order: one list.
     *
     * @var list<class-string<ModuleInterface>>
     */
    public const array ORDER = [
        ShellModule::class,
        InfoModule::class,
        InboxModule::class,
        HomeModule::class,
        ProfileModule::class,
        MapModule::class,
        ProgramsModule::class,
        PushModule::class,
        HandbookModule::class,
        AdminModule::class,
    ];

    /**
     * The modules of every event, whatever its `features`: the offline page and the precache
     * list, Domů, and Profil with the login.
     *
     * @var list<class-string<ModuleInterface>>
     */
    public const array CORE = [ShellModule::class, HomeModule::class, ProfileModule::class];

    /** @var array<string, class-string<ModuleInterface>> */
    private const array MODULES = [
        'info' => InfoModule::class,
        'inbox' => InboxModule::class,
        'map' => MapModule::class,
        'handbook' => HandbookModule::class,
        'programs' => ProgramsModule::class,
        'push' => PushModule::class,
    ];

    /** @return class-string<ModuleInterface> */
    public static function classFor(string $feature): string
    {
        return self::MODULES[$feature]
            ?? throw new \RuntimeException(sprintf('Unknown module: "%s"', $feature));
    }

    /**
     * The modules of the event's enabled features, in ORDER, and the admin page with `push`:
     * it sends the notifications, but is no feature an event could enable on its own. An
     * unknown feature throws, as classFor() does.
     *
     * @return list<class-string<ModuleInterface>>
     */
    public static function classesFor(EventConfig $event): array
    {
        $enabled = [];
        foreach ($event->features as $feature) {
            $enabled[] = self::classFor($feature);
        }
        if (in_array(PushModule::class, $enabled, true)) {
            $enabled[] = AdminModule::class;
        }

        return array_values(array_filter(self::ORDER, static fn(string $class): bool => in_array($class, $enabled, true)));
    }

    /**
     * Every module of the event: CORE and the enabled features' modules, in ORDER.
     *
     * @return list<class-string<ModuleInterface>>
     */
    public static function modulesFor(EventConfig $event): array
    {
        $classes = [...self::CORE, ...self::classesFor($event)];

        return array_values(array_filter(self::ORDER, static fn(string $class): bool => in_array($class, $classes, true)));
    }
}
