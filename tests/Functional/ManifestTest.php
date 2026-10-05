<?php

declare(strict_types=1);

namespace Tests\Functional;

/** An installed event must open, and stay, inside its own prefix. */
final class ManifestTest extends AppTestCase
{
    public function testEveryManifestIsScopedToItsEvent(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/www/events/*/site.webmanifest') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $slug = basename(dirname($file));
            $manifest = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('/' . $slug . '/', $manifest['start_url'], $slug);
            self::assertSame('/' . $slug . '/', $manifest['scope'] ?? null, $slug);
        }
    }

    public function testEveryEventHasAManifestWithItsIcons(): void
    {
        $root = dirname(__DIR__, 2);
        $configs = glob($root . '/events/*/config.php') ?: [];
        self::assertNotEmpty($configs);
        foreach ($configs as $config) {
            $slug = basename(dirname($config));
            $file = $root . '/www/events/' . $slug . '/site.webmanifest';
            self::assertFileExists($file, $slug . ' manifest');
            $manifest = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            self::assertNotEmpty($manifest['icons'], $slug);
            foreach ($manifest['icons'] as $icon) {
                self::assertFileExists($root . '/www' . $icon['src'], $slug . ' ' . $icon['src']);
            }
            foreach (['favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png'] as $name) {
                self::assertFileExists($root . '/www/events/' . $slug . '/' . $name, $slug . ' ' . $name);
            }
        }
    }

    public function testTheServiceWorkerIsRegisteredWithTheEventScope(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/www/push.js');
        self::assertStringContainsString("register('sw.js', {scope: meta('event-base')})", $js);
        self::assertStringContainsString("fetch(meta('event-base') + 'push/subscribe'", $js);
        self::assertStringContainsString('const scope = self.registration.scope', (string) file_get_contents(dirname(__DIR__, 2) . '/www/sw.js'));
    }

    /** @return iterable<string, array{string, array}> slug => decoded manifest, for every event */
    private static function manifests(): iterable
    {
        $root = dirname(__DIR__, 2);
        foreach (glob($root . '/events/*/config.php') ?: [] as $config) {
            $slug = basename(dirname($config));
            yield $slug => [$slug, json_decode((string) file_get_contents($root . '/www/events/' . $slug . '/site.webmanifest'), true, flags: JSON_THROW_ON_ERROR)];
        }
    }

    public function testEveryManifestHasAMaskableIconOnDisk(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::manifests() as [$slug, $manifest]) {
            $maskable = array_values(array_filter($manifest['icons'], static fn (array $icon): bool => ($icon['purpose'] ?? 'any') === 'maskable'));
            self::assertCount(1, $maskable, $slug);
            self::assertSame('/events/' . $slug . '/maskable-512.png', $maskable[0]['src'], $slug);
            self::assertSame(['512x512', 'image/png'], [$maskable[0]['sizes'], $maskable[0]['type']], $slug);
            $size = getimagesize($root . '/www' . $maskable[0]['src']);
            self::assertNotFalse($size, $slug);
            self::assertSame([512, 512], [$size[0], $size[1]], $slug);
            // the two existing icons stay "any"
            foreach ($manifest['icons'] as $icon) {
                if ($icon['src'] !== $maskable[0]['src']) {
                    self::assertSame('any', $icon['purpose'] ?? 'any', $slug . ' ' . $icon['src']);
                }
            }
        }
    }

    public function testEveryManifestIdentifiesItsEvent(): void
    {
        foreach (self::manifests() as [$slug, $manifest]) {
            self::assertSame('/' . $slug . '/', $manifest['id'] ?? null, $slug);
            self::assertSame('cs', $manifest['lang'] ?? null, $slug);
            self::assertSame('portrait', $manifest['orientation'] ?? null, $slug);
        }
    }

    /** A shortcut to a screen the event does not have would open a 404 from the home screen. */
    public function testEveryShortcutIsARouteOfAnEnabledFeature(): void
    {
        $screens = ['programs' => ['Program', 'programy'], 'news' => ['Novinky', 'novinky']];
        foreach (self::manifests() as [$slug, $manifest]) {
            $event = \App\EventConfig::load(dirname(__DIR__, 2) . '/events', $slug);
            $expected = [];
            foreach ($screens as $feature => [$name, $path]) {
                if ($event->isEnabled($feature)) {
                    $expected[] = ['name' => $name, 'url' => '/' . $slug . '/' . $path];
                }
            }
            self::assertSame($expected, $manifest['shortcuts'] ?? [], $slug);
        }
    }

    public function testTheMaskIconIsLinkedOnlyForAnEventThatNamesOne(): void
    {
        $root = dirname(__DIR__, 2);
        $named = [];
        foreach (glob($root . '/events/*/config.php') ?: [] as $config) {
            $slug = basename(dirname($config));
            $pinned = \App\EventConfig::load($root . '/events', $slug)->get('assets')['pinnedTab'] ?? null;
            $html = (string) $this->request($this->createApp($slug), 'GET', '/')->getBody();
            if ($pinned === null) {
                self::assertStringNotContainsString('rel="mask-icon"', $html, $slug);
                continue;
            }
            $named[] = $slug;
            self::assertFileExists($root . '/www/' . $pinned, $slug);
            self::assertStringContainsString('<link rel="mask-icon" href="' . $pinned . '"', $html, $slug);
        }
        sort($named);

        self::assertSame(['obrok19', 'obrok27'], $named, 'the two events that ship the drawing name it');
    }
}
