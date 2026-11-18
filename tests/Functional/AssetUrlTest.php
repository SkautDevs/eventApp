<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Http\ScriptVersion;
use App\Module\Modules;

/** The layout's stylesheet and scripts carry their content hash; nobody bumps a number by hand. */
final class AssetUrlTest extends AppTestCase
{
    public function testEveryLocalScriptAndTheStylesheetCarryTheirContentHash(): void
    {
        $root = dirname(__DIR__, 2);
        $scripts = new ScriptVersion($root . '/www');
        foreach (glob($root . '/events/*/config.php') ?: [] as $config) {
            $slug = basename(dirname($config));
            $app = $this->createApp($slug);
            $html = (string) $this->request($app, 'GET', '/')->getBody();

            $style = substr((string) hash_file('sha256', $root . '/www/style.css'), 0, 8);
            self::assertStringContainsString('href="style.css?v=' . $style . '"', $html, $slug);

            // the tags the layout emits for this event: one entry per module with a script,
            // in the modules' order
            $modules = $app->getContainer()?->get(Modules::class);
            self::assertInstanceOf(Modules::class, $modules);
            $expected = $modules->scripts();
            self::assertNotSame([], $expected, $slug);
            preg_match_all('~<script type="module" src="(/js/([0-9a-f]{8})/([^"]+))"></script>~', $html, $matches);
            self::assertSame(array_map($scripts->url(...), $expected), $matches[1], $slug);
            foreach ($matches[2] as $hash) {
                self::assertSame($scripts->hash(), $hash, $slug);
            }
            self::assertSame(count($expected), substr_count($html, '<script type="module"'), $slug);
        }
    }

    public function testTheLayoutNamesNoVersionByHand(): void
    {
        self::assertStringNotContainsString('?v=', (string) file_get_contents(dirname(__DIR__, 2) . '/templates/_layout.twig'));
    }
}
