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

    public function testNewsWithoutPushFailsAtBoot(): void
    {
        $dir = sys_get_temp_dir() . '/eventconfig-' . uniqid();
        mkdir($dir . '/broken', 0o777, true);
        file_put_contents($dir . '/broken/config.php', '<?php return ' . var_export([
            'name' => 'Broken',
            'features' => ['news'], // News lists the sent notifications, so it needs push
            'colors' => (require dirname(__DIR__) . '/fixtures/events/minimal/config.php')['colors'],
        ], true) . ';');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('news without push');
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

    /**
     * Unlike the palette the theme map is optional and unvalidated, so it has to be
     * an array even when the config never mentions it — the layout iterates it.
     */
    public function testThemeIsOptionalAndAlwaysAnArray(): void
    {
        $fixtures = dirname(__DIR__) . '/fixtures/events';

        self::assertSame([], EventConfig::load($fixtures, 'minimal')->theme);
        self::assertSame([], EventConfig::load($this->eventsDir, 'obrok19')->theme);
        self::assertSame('0', EventConfig::load($this->eventsDir, 'obrok27')->theme['radius']);
    }

    public function testContentLoadsFileAndDefaultsToEmpty(): void
    {
        $config = EventConfig::load($this->eventsDir, 'obrok19');

        self::assertNotEmpty($config->content('links'));
        self::assertSame([], $config->content('neexistuje'));
    }

    public function testListedDefaultsToFalseAndDatesToNull(): void
    {
        $event = EventConfig::load(dirname(__DIR__) . '/fixtures/events', 'minimal');
        self::assertFalse($event->listed);
        self::assertNull($event->dates);
    }

    /** Loads a minimal event whose config gains $extra lines; the temp dir is always removed. */
    private function loadWithConfigLines(string $extra): EventConfig
    {
        $dir = sys_get_temp_dir() . '/ev-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tmp26', 0777, true);
        try {
            $minimal = file_get_contents(dirname(__DIR__) . '/fixtures/events/minimal/config.php');
            file_put_contents($dir . '/tmp26/config.php', str_replace("return [", "return [\n" . $extra, $minimal));

            return EventConfig::load($dir, 'tmp26');
        } finally {
            @unlink($dir . '/tmp26/config.php');
            @rmdir($dir . '/tmp26');
            @rmdir($dir);
        }
    }

    public function testAListedEventWithoutDatesFailsAtBoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dates');
        $this->loadWithConfigLines("    'listed' => true,");
    }

    /** @return array<string, array{string}> */
    public static function invalidDates(): array
    {
        return [
            'not an array' => ["'dates' => '2025-06-05',"],
            'impossible day' => ["'dates' => ['start' => '2025-02-31', 'end' => '2025-03-02'],"],
            'end before start' => ["'dates' => ['start' => '2025-06-08', 'end' => '2025-06-05'],"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDates')]
    public function testInvalidDatesFailAtBoot(string $line): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dates');
        $this->loadWithConfigLines('    ' . $line);
    }

    public function testASlugWithATrailingNewlineIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        EventConfig::load(dirname(__DIR__) . '/fixtures/events', "minimal\n");
    }

    public function testEnvFallsBackToTheProcessEnvironment(): void
    {
        $event = EventConfig::load(dirname(__DIR__) . '/fixtures/events', 'minimal');
        putenv('ADMIN_TOKEN_MINIMAL=from-process');
        putenv('ADMIN_TOKEN=instance-wide');
        try {
            self::assertSame('from-process', $event->env('ADMIN_TOKEN'));
            putenv('ADMIN_TOKEN_MINIMAL');
            self::assertSame('', $event->env('ADMIN_TOKEN'));
        } finally {
            putenv('ADMIN_TOKEN_MINIMAL');
            putenv('ADMIN_TOKEN');
        }
    }

    public function testEnvKeyIsSuffixedWithTheUpperCasedSlug(): void
    {
        $event = EventConfig::load(dirname(__DIR__) . '/fixtures/events', 'minimal');
        self::assertSame('ADMIN_TOKEN_MINIMAL', $event->envKey('ADMIN_TOKEN'));
    }

    public function testEnvReadsTheSuffixedVariableOnly(): void
    {
        $event = EventConfig::load(dirname(__DIR__) . '/fixtures/events', 'minimal');
        $_ENV['ADMIN_TOKEN'] = 'instance-wide';
        $_ENV['ADMIN_TOKEN_MINIMAL'] = 'per-event';
        try {
            self::assertSame('per-event', $event->env('ADMIN_TOKEN'));
            unset($_ENV['ADMIN_TOKEN_MINIMAL']);
            self::assertSame('fallback', $event->env('ADMIN_TOKEN', 'fallback'));
        } finally {
            unset($_ENV['ADMIN_TOKEN'], $_ENV['ADMIN_TOKEN_MINIMAL']);
        }
    }
}
