<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use PHPUnit\Framework\TestCase;

final class EventConfigTest extends TestCase
{
    private string $eventsDir;

    protected function setUp(): void
    {
        $this->eventsDir = dirname(__DIR__, 2) . '/events';
    }

    public function testLoadsObrok19(): void
    {
        $config = EventConfig::load($this->eventsDir, 'obrok19');

        self::assertSame('obrok19', $config->slug);
        self::assertSame('Obrok 2019', $config->name);
        self::assertContains('news', $config->features);
        self::assertTrue($config->isEnabled('news'));
        self::assertFalse($config->isEnabled('neexistuje'));
        self::assertSame('#2a9272', $config->colors['base']);
        self::assertSame('Putování', $config->sections[10]['title']);
    }

    public function testUnknownSlugThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        EventConfig::load($this->eventsDir, 'neexistuje');
    }

    public function testTraversalSlugThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        EventConfig::load($this->eventsDir, '../etc');
    }

    public function testIncompletePaletteThrows(): void
    {
        $dir = sys_get_temp_dir() . '/eventconfig-' . uniqid();
        mkdir($dir . '/broken', 0o777, true);
        file_put_contents($dir . '/broken/config.php', '<?php return ' . var_export([
            'name' => 'Broken',
            'features' => [],
            'colors' => ['base' => '#000000'], // the rest of the palette is missing
        ], true) . ';');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('is missing the colour');
            EventConfig::load($dir, 'broken');
        } finally {
            unlink($dir . '/broken/config.php');
            rmdir($dir . '/broken');
            rmdir($dir);
        }
    }

    public function testEveryRealEventLoads(): void
    {
        // the palette check runs at boot, so this is what stops a half-themed event shipping
        foreach (glob($this->eventsDir . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            self::assertSame($slug, EventConfig::load($this->eventsDir, $slug)->slug);
        }
    }

    public function testContentLoadsFileAndDefaultsToEmpty(): void
    {
        $config = EventConfig::load($this->eventsDir, 'obrok19');

        self::assertNotEmpty($config->content('news'));
        self::assertSame([], $config->content('neexistuje'));
    }
}
