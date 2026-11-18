<?php

declare(strict_types=1);

namespace Tests;

/**
 * The core stylesheets under www/css/ as one text, for the tests that read the app's CSS:
 * every module's sheet, whatever the event, concatenated in the order the layout links
 * them for an event that registers every module (base, the modules' in ModuleRegistry
 * order, motion).
 */
final class CoreStylesheet
{
    /** @var list<string> */
    private const array FILES = [
        'css/base.css',
        'css/shell.css',
        'css/info.css',
        'css/home.css',
        'css/profile.css',
        'css/map.css',
        'css/programs.css',
        'css/admin.css',
        'css/motion.css',
    ];

    /**
     * The nine files, as paths under www/, in link order.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        return self::FILES;
    }

    /** The nine files' contents, joined with "\n" in link order. */
    public static function read(string $www = __DIR__ . '/../www'): string
    {
        $parts = [];
        foreach (self::FILES as $file) {
            $parts[] = (string) file_get_contents($www . '/' . $file);
        }

        return implode("\n", $parts);
    }
}
