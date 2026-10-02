<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;

/** An installed event must open, and stay, inside its own prefix. */
final class ManifestTest extends TestCase
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
}
