<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Module\ModuleRegistry;
use App\Module\NewsModule;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    public function testKnownFeature(): void
    {
        self::assertSame(NewsModule::class, ModuleRegistry::classFor('news'));
    }

    public function testUnknownFeatureThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        ModuleRegistry::classFor('teleport');
    }
}
