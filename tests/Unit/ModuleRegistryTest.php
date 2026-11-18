<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use App\Module\Admin\AdminModule;
use App\Module\Handbook\HandbookModule;
use App\Module\Info\InfoModule;
use App\Module\Map\MapModule;
use App\Module\ModuleRegistry;
use App\Module\Programs\ProgramsModule;
use App\Module\Push\PushModule;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    public function testKnownFeature(): void
    {
        self::assertSame(InfoModule::class, ModuleRegistry::classFor('info'));
    }

    /** Informace replaced both; nothing is kept for an old config. */
    public function testNewsAndLinksAreGone(): void
    {
        foreach (['news', 'links'] as $feature) {
            try {
                ModuleRegistry::classFor($feature);
                self::fail($feature . ' is still a module');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($feature, $e->getMessage());
            }
        }
    }

    public function testUnknownFeatureThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        ModuleRegistry::classFor('teleport');
    }

    /** The admin page comes with push, but no event can enable it as a feature of its own. */
    public function testAdminIsNoFeature(): void
    {
        $this->expectException(\RuntimeException::class);
        ModuleRegistry::classFor('admin');
    }

    /** obrok27 lists map, programs, handbook, info, push: the modules come in ORDER instead. */
    public function testAnEventsModulesComeInTheRegistrysOrder(): void
    {
        $event = EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok27');

        self::assertSame(
            [InfoModule::class, MapModule::class, ProgramsModule::class, PushModule::class, HandbookModule::class, AdminModule::class],
            ModuleRegistry::classesFor($event),
        );
    }

    /** A feature the event does not enable has no module. */
    public function testOnlyTheEnabledFeaturesHaveModules(): void
    {
        $event = EventConfig::load(dirname(__DIR__) . '/fixtures/events', 'programs');

        self::assertSame([ProgramsModule::class], ModuleRegistry::classesFor($event));
    }
}
