<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Module\ModuleRegistry;
use App\Module\InfoModule;
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
}
