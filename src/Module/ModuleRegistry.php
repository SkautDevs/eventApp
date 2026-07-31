<?php

declare(strict_types=1);

namespace App\Module;

final class ModuleRegistry
{
    /** @var array<string, class-string<ModuleInterface>> */
    private const MODULES = [
        'news' => NewsModule::class,
        'links' => LinksModule::class,
        'map' => MapModule::class,
        'handbook' => HandbookModule::class,
        'programs' => ProgramsModule::class,
        'harmonogram' => HarmonogramModule::class,
        'push' => PushModule::class,
    ];

    /** @return class-string<ModuleInterface> */
    public static function classFor(string $feature): string
    {
        return self::MODULES[$feature]
            ?? throw new \RuntimeException(sprintf('Neznámý modul: "%s"', $feature));
    }
}
