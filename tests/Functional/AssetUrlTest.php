<?php

declare(strict_types=1);

namespace Tests\Functional;

/** The layout's stylesheet and scripts carry their content hash; nobody bumps a number by hand. */
final class AssetUrlTest extends AppTestCase
{
    public function testEveryLocalScriptAndTheStylesheetCarryTheirContentHash(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/')->getBody();

        preg_match_all('~(?:src|href)="(style\.css|app\.js|shell\.js|push\.js|programs\.js)\?v=([0-9a-f]{8})"~', $html, $matches, PREG_SET_ORDER);
        $found = [];
        foreach ($matches as [, $file, $hash]) {
            self::assertSame(substr((string) hash_file('sha256', dirname(__DIR__, 2) . '/www/' . $file), 0, 8), $hash, $file);
            $found[] = $file;
        }
        sort($found);

        self::assertSame(['app.js', 'programs.js', 'push.js', 'shell.js', 'style.css'], $found);
    }

    public function testTheLayoutNamesNoVersionByHand(): void
    {
        self::assertStringNotContainsString('?v=', (string) file_get_contents(dirname(__DIR__, 2) . '/templates/_layout.twig'));
    }
}
