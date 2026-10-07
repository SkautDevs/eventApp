<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Fonts;
use PHPUnit\Framework\TestCase;

/**
 * The table the worker's offline set is built from and the stylesheets that actually
 * load the fonts must name the same files: a face the table forgot is a font missing
 * offline, and a table entry nothing loads is a 404 risk in the precache list.
 */
final class FontsTest extends TestCase
{
    private const string WWW = __DIR__ . '/../../www';

    /** @return list<string> every file of every family, as web paths */
    private static function allTableFiles(): array
    {
        return Fonts::files(array_keys(Fonts::FILES));
    }

    public function testEveryFileInTheTableExists(): void
    {
        foreach (Fonts::FILES as $family => $files) {
            self::assertNotEmpty($files, $family);
            foreach ($files as $path) {
                self::assertStringStartsWith('/', $path);
                self::assertMatchesRegularExpression('~^/(fonts|vendor)/~', $path, $path . ' is outside the immutable prefixes');
                self::assertFileExists(self::WWW . $path);
                if (str_ends_with($path, '.woff2')) {
                    self::assertSame('wOF2', (string) file_get_contents(self::WWW . $path, length: 4), $path);
                }
            }
        }
    }

    public function testEveryFontFaceInTheStylesheetIsInTheTable(): void
    {
        $css = (string) file_get_contents(self::WWW . '/style.css');
        preg_match_all('/@font-face\s*\{[^}]*\}/', $css, $faces);
        self::assertNotEmpty($faces[0], 'style.css declares no @font-face at all');

        $table = self::allTableFiles();
        $urls = [];
        foreach ($faces[0] as $face) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', $face, $m);
            self::assertCount(1, $m[1], 'one src, one woff2 per face: ' . $face);
            foreach ($m[1] as $url) {
                self::assertStringEndsWith('.woff2', $url);
                self::assertContains($url, $table, $url . ' is loaded by style.css but missing from App\Http\Fonts');
                $urls[] = $url;
            }
        }

        // and the other way round: every font file the table names for the shell's own
        // families is loaded by some face, so nothing is precached that nothing reads
        foreach (Fonts::files(['themix', 'skautbold', 'Montserrat']) as $path) {
            self::assertContains($path, $urls, $path . ' is in the table but no @font-face loads it');
        }
    }

    public function testFontAwesomesStylesheetResolvesToTableFiles(): void
    {
        $files = Fonts::FILES['Font Awesome'];
        $stylesheets = array_values(array_filter($files, static fn (string $path): bool => str_ends_with($path, '.css')));
        self::assertCount(1, $stylesheets);
        $css = (string) file_get_contents(self::WWW . $stylesheets[0]);
        // shipped byte for byte, licence header included
        self::assertStringStartsWith("/*!\n * Font Awesome Free 5.8.1", $css);

        preg_match_all('/url\(([^)]+\.woff2)\)/', $css, $m);
        self::assertCount(3, array_unique($m[1]));
        $woff2 = [];
        foreach (array_unique($m[1]) as $relative) {
            $resolved = self::resolve(dirname($stylesheets[0]), $relative);
            self::assertContains($resolved, $files, $relative . ' resolves to ' . $resolved . ', which is not in the table');
            $woff2[] = $resolved;
        }
        // the table carries exactly the stylesheet and those three
        self::assertEqualsCanonicalizing([$stylesheets[0], ...$woff2], $files);
    }

    public function testFilesKeepsTheOrderAndSkipsUnknownFamilies(): void
    {
        self::assertSame([], Fonts::files(['Open Sans', 'system-ui']));
        self::assertSame(
            [...Fonts::FILES['skautbold'], ...Fonts::FILES['Font Awesome']],
            Fonts::files(['skautbold', 'Arial', 'Font Awesome', 'skautbold']),
        );
        // CSS family names are case-insensitive, and so is the table
        self::assertSame(Fonts::FILES['Montserrat'], Fonts::files(['montserrat']));
    }

    private static function resolve(string $dir, string $relative): string
    {
        $parts = explode('/', trim($dir, '/'));
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '.') {
                $parts[] = $segment;
            }
        }

        return '/' . implode('/', $parts);
    }
}
