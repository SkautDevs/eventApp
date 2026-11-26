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
        self::assertContains('info', $config->features);
        self::assertTrue($config->isEnabled('info'));
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

    public function testInfoWithoutPushFailsAtBoot(): void
    {
        $dir = sys_get_temp_dir() . '/eventconfig-' . uniqid();
        mkdir($dir . '/broken', 0o777, true);
        file_put_contents($dir . '/broken/config.php', '<?php return ' . var_export([
            'name' => 'Broken',
            'features' => ['info'], // Informace lists the sent notifications, so it needs push
            'colors' => (require dirname(__DIR__) . '/fixtures/events/minimal/config.php')['colors'],
        ], true) . ';');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('info without push');
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

    /** No theme font: the shell's own pair, plus the icons every layout links. */
    public function testFontFamiliesDefaultToThemixAndSkautbold(): void
    {
        $event = EventConfig::load(dirname(__DIR__) . '/fixtures/events', 'minimal');

        self::assertSame(['themix', 'skautbold', 'Font Awesome'], $event->fontFamilies());
    }

    /** obrok27 pairs TheMix for text with Montserrat for headings: skautbold does not come along. */
    public function testFontFamiliesFollowTheTheme(): void
    {
        self::assertSame(['themix', 'Montserrat', 'Font Awesome'], EventConfig::load($this->eventsDir, 'obrok27')->fontFamilies());
    }

    /** The first family of each value counts, its quotes stripped; a value may set only one of the two. */
    public function testFontFamiliesTakeTheFirstFamilyUnquoted(): void
    {
        $event = $this->loadWithConfigLines("    'theme' => ['font' => '\"Open Sans\", Arial, sans-serif'],");
        self::assertSame(['Open Sans', 'skautbold', 'Font Awesome'], $event->fontFamilies());

        $event = $this->loadWithConfigLines("    'theme' => ['font-display' => \"  'Montserrat' , serif\"],");
        self::assertSame(['themix', 'Montserrat', 'Font Awesome'], $event->fontFamilies());
    }

    public function testLinksComeFromTheContentFileAndNoEmergencyFileIsNull(): void
    {
        $config = EventConfig::load($this->eventsDir, 'obrok19');

        self::assertNotEmpty($config->links());
        self::assertNull($config->emergency());
    }

    public function testTheMapsKeepTheConfigsOwnOrder(): void
    {
        $raw = require dirname(__DIR__, 2) . '/events/obrok27/config.php';
        $event = EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok27');
        self::assertSame(array_keys($raw['colors']), array_keys($event->colors));
        self::assertSame(array_keys($raw['roles']['light']), array_keys($event->roles->light));
        self::assertSame(array_keys($raw['theme']), array_keys($event->theme));
    }

    public function testTheAssetPathsKeepTheirOrderAndLeaveTheStylesheetOut(): void
    {
        $raw = require dirname(__DIR__, 2) . '/events/obrok27/config.php';
        $expected = array_filter($raw['assets'], static fn($path, $key) => $key !== 'stylesheet' && is_string($path) && $path !== '', ARRAY_FILTER_USE_BOTH);
        self::assertSame($expected, EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok27')->assets->paths());
    }

    public function testAnEventWithoutADarkSetHasAnEmptyOne(): void
    {
        self::assertSame([], EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok19')->roles->dark);
    }

    public function testAnEventWithoutContentFilesHasNoLinksAndNoEmergency(): void
    {
        $event = EventConfig::load(dirname(__DIR__, 2) . '/events', 'miquik26');
        self::assertSame([], $event->links());
        self::assertNull($event->emergency());
    }

    public function testListedDefaultsToFalseAndDatesToNull(): void
    {
        $event = EventConfig::load(dirname(__DIR__) . '/fixtures/events', 'minimal');
        self::assertFalse($event->listed);
        self::assertNull($event->dates);
    }

    /**
     * Loads a minimal event whose config gains $extra lines at its end, where a key the
     * minimal config already has is overridden; the temp dir is always removed.
     */
    private function loadWithConfigLines(string $extra): EventConfig
    {
        $dir = sys_get_temp_dir() . '/ev-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tmp26', 0777, true);
        try {
            $minimal = (string) file_get_contents(dirname(__DIR__) . '/fixtures/events/minimal/config.php');
            $end = (int) strrpos($minimal, '];');
            file_put_contents($dir . '/tmp26/config.php', substr($minimal, 0, $end) . $extra . "\n" . substr($minimal, $end));

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

    /** @return array<string, array{string, string}> */
    public static function untypedValues(): array
    {
        $colors = "['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'";

        return [
            'a name that is a number' => ["'name' => 5,", 'has a name that is not a string'],
            'features that are a string' => ["'features' => 'info',", 'has features that are not a list of strings'],
            'features that are a map' => ["'features' => ['a' => 'push'],", 'has features that are not a list of strings'],
            'features with a number' => ["'features' => ['push', 7],", 'has features that are not a list of strings'],
            'colors that are a string' => ["'colors' => '#000000',", 'has colors that is not a map'],
            'a colour that is an array' => ["'colors' => " . $colors . ", 'extra' => ['#000000']],", 'has colors.extra that is not a string'],
            'theme that is a string' => ["'theme' => 'radius: 0',", 'has theme that is not a map'],
            'a theme value that is a bool' => ["'theme' => ['radius' => true],", 'has theme.radius that is not a string'],
            'a theme value that is null' => ["'theme' => ['radius' => null],", 'has theme.radius that is not a string'],
            'a theme key that is a number' => ["'theme' => ['0'],", 'has theme.0 that is not a string'],
            'roles that are a string' => ["'roles' => 'light',", 'has invalid roles (expected light and dark)'],
            'a light set that is a string' => ["'roles' => ['light' => '#ffffff'],", 'has roles.light that is not a map'],
            'a dark set that is a string' => ["'roles' => ['light' => [], 'dark' => '#000000'],", 'has roles.dark that is not a map'],
            'a light role that is an array' => ["'roles' => ['light' => ['ground' => ['#ffffff']]],", 'has roles.light.ground that is not a string'],
            'a dark role that is a bool' => ["'roles' => ['dark' => ['ground' => false]],", 'has roles.dark.ground that is not a string'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untypedValues')]
    public function testAValueOfTheWrongTypeFailsAtBoot(string $line, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        $this->loadWithConfigLines('    ' . $line);
    }

    /**
     * A number in a CSS map is taken as its string form: the layout printed `700` and
     * `1.5` as they are, and still prints them the same way.
     */
    public function testANumberInTheThemeBootsAndRendersLikeItsStringForm(): void
    {
        $event = $this->loadWithConfigLines("    'theme' => ['font-display-weight' => 700, 'line' => 1.5],");
        self::assertSame(['font-display-weight' => '700', 'line' => '1.5'], $event->theme);

        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'theme' => '{% for name, value in event.theme %}--theme-{{ name }}: {{ value|raw }};{% endfor %}',
        ]));
        $before = $twig->render('theme', ['event' => ['theme' => ['font-display-weight' => 700, 'line' => 1.5]]]);
        self::assertSame('--theme-font-display-weight: 700;--theme-line: 1.5;', $before);
        self::assertSame($before, $twig->render('theme', ['event' => $event]));
    }

    public function testASlugWithATrailingNewlineIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        EventConfig::load(dirname(__DIR__) . '/fixtures/events', "minimal\n");
    }

    public function testCspIsOptionalAndAlwaysAnArray(): void
    {
        self::assertSame([], EventConfig::load(dirname(__DIR__) . '/fixtures/events', 'minimal')->csp);
        foreach (glob($this->eventsDir . '/*/config.php') ?: [] as $path) {
            // no shipped event needs the key: fonts and the map are derived
            self::assertSame([], EventConfig::load($this->eventsDir, basename(dirname($path)))->csp);
        }
    }

    public function testCspCarriesExtraOriginsPerDirective(): void
    {
        $event = $this->loadWithConfigLines("    'csp' => ['img-src' => ['https://photos.example'], 'frame-src' => ['https://video.example:8443']],");

        self::assertSame(['img-src' => ['https://photos.example'], 'frame-src' => ['https://video.example:8443']], $event->csp);
    }

    /** @return array<string, array{string}> */
    public static function invalidCsp(): array
    {
        return [
            'not an array' => ["'csp' => 'https://x.example',"],
            'unknown directive' => ["'csp' => ['worker-src' => ['https://x.example']],"],
            'a directive the app owns' => ["'csp' => ['default-src' => ['https://x.example']],"],
            'origins not a list' => ["'csp' => ['img-src' => 'https://x.example'],"],
            'plain http' => ["'csp' => ['img-src' => ['http://x.example']],"],
            'a path' => ["'csp' => ['img-src' => ['https://x.example/photos']],"],
            'a trailing slash' => ["'csp' => ['img-src' => ['https://x.example/']],"],
            'a keyword' => ["'csp' => ['script-src' => [\"'unsafe-inline'\"]],"],
            'a wildcard' => ["'csp' => ['img-src' => ['https://*']],"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCsp')]
    public function testAnInvalidCspFailsAtBoot(string $line): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('csp');
        $this->loadWithConfigLines('    ' . $line);
    }

    public function testPlanBoundsAreRead(): void
    {
        $event = EventConfig::load($this->eventsDir, 'obrok27');

        self::assertNotNull($event->map?->planBounds);
        self::assertSame(50.343810, $event->map->planBounds->north);
        self::assertSame(15.938067, $event->map->planBounds->east);
        self::assertNull(EventConfig::load($this->eventsDir, 'obrok19')->map?->planBounds);
    }

    public function testInvalidPlanBoundsFailAtLoad(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Event config "tmp26" has an invalid map.planBounds (expected north > south and east > west, in degrees)');
        $this->loadWithConfigLines("    'map' => ['image' => 'events/obrok19/plan-example.png', 'planBounds' => ['north' => 50.00, 'south' => 50.01, 'west' => 15.00, 'east' => 15.02]],");
    }

    public function testPlanBoundsWithoutAPlanFailAtLoad(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Event config "tmp26" has map.planBounds without map.image');
        $this->loadWithConfigLines("    'map' => ['embedUrl' => 'https://www.google.com/maps/d/embed?mid=test', 'planBounds' => ['north' => 50.01, 'south' => 50.00, 'west' => 15.00, 'east' => 15.02]],");
    }
}
