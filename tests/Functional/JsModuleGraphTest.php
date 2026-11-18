<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Http\ScriptGraph;

/**
 * The scripts under www/js are one graph of native ES modules: each module's entry is
 * <dir>/index.js, every import names a file, every file is reached, and nothing is loaded
 * late (a module loaded after DOMContentLoaded misses it silently).
 */
final class JsModuleGraphTest extends AppTestCase
{
    /** Every entry: one index.js per directory under www/js. @return list<string> */
    private static function everyEntry(): array
    {
        $root = dirname(__DIR__, 2) . '/www/js/';

        return array_map(static fn(string $file): string => substr($file, strlen($root)), glob($root . '*/index.js') ?: []);
    }

    /** Every script under www/js (not tests/js), comments stripped by ScriptGraph's own stripper. @return array<string, string> relative path => source */
    private static function scripts(): array
    {
        $root = dirname(__DIR__, 2) . '/www/js/';
        $scripts = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->getExtension() === 'js') {
                $scripts[substr($file->getPathname(), strlen($root))] = ScriptGraph::stripComments((string) file_get_contents($file->getPathname()));
            }
        }
        ksort($scripts);

        return $scripts;
    }

    public function testEveryImportNamesAFileAndEveryFileIsReached(): void
    {
        $graph = new ScriptGraph(dirname(__DIR__, 2) . '/www');
        self::assertSame([], $graph->unresolved());
        self::assertSame([], $graph->orphans(self::everyEntry()));
    }

    public function testNothingIsLoadedLate(): void
    {
        foreach (self::scripts() as $path => $source) {
            self::assertDoesNotMatchRegularExpression('/\bimport\s*\(/', $source, $path . ' uses a dynamic import');
            // column 0 only: an await inside an async function is indented
            self::assertDoesNotMatchRegularExpression('/^(?:(?:export\s+)?(?:const|let)\s+[^\n=]*=\s*)?await\b|^for\s+await\b/m', $source, $path . ' awaits at the top level');
        }
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/_layout.twig');
        self::assertDoesNotMatchRegularExpression('/<script[^>]*\basync\b/', $layout);
    }

    public function testTheLoaderStartsBeforeEveryOtherScreensScript(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();
        preg_match_all('#<script type="module" src="/js/[0-9a-f]{8}/([a-z]+)/index\.js"></script>#', $html, $entries);
        self::assertSame(['shell', 'home', 'profile', 'map', 'programs', 'push'], $entries[1]);
    }

    public function testTheEventNamesLiveInOneFile(): void
    {
        foreach (self::scripts() as $path => $source) {
            if ($path === 'events.js') {
                continue;
            }
            self::assertDoesNotMatchRegularExpression("/'(screen:(shown|hidden|morphed|freshness|loading|loaded)|sheet:closed|pg:tick)'/", $source, $path);
        }
    }

    public function testThereIsNoGlobalEntryPointAndNoLegacySyntax(): void
    {
        foreach (self::scripts() as $path => $source) {
            self::assertDoesNotMatchRegularExpression('/\bwindow\.(pgInit|mapInit)\b/', $source, $path);
            self::assertDoesNotMatchRegularExpression('/(^|[\s(;])var\s/m', $source, $path);
            self::assertStringNotContainsString("'use strict'", $source, $path);
            self::assertDoesNotMatchRegularExpression('/^\(function\s*\(/m', $source, $path . ' is wrapped in an IIFE');
        }
        self::assertSame([], glob(dirname(__DIR__, 2) . '/www/js/*.js') === false ? [] : array_map('basename', array_diff(glob(dirname(__DIR__, 2) . '/www/js/*.js'), [dirname(__DIR__, 2) . '/www/js/events.js'])));
    }

    public function testTheTestClockIsReadAtCallTime(): void
    {
        $clock = (string) file_get_contents(dirname(__DIR__, 2) . '/www/js/ui/clock.js');
        self::assertMatchesRegularExpression('/export function now\(\) \{[^}]*window\.pgClock/s', $clock);
        foreach (self::scripts() as $path => $source) {
            if ($path !== 'ui/clock.js') {
                self::assertStringNotContainsString('pgClock', $source, $path);
            }
        }
    }
}
