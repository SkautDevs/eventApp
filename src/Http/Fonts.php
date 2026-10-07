<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The self-hosted font files, by the family an event renders with
 * (EventConfig::fontFamilies()). Precache puts an event's families into the worker's
 * offline set, so an installed app keeps its typefaces and every icon without a network.
 *
 * The paths sit under /fonts/ and /vendor/, which carry no content hash: a changed file
 * gets a new name, never new bytes under the old one (a year of `immutable` in nginx and
 * .htaccess, cache-first in www/sw.js). FontsTest keeps this table, the `@font-face`
 * rules of www/style.css and Font Awesome's own stylesheet in step.
 */
final class Fonts
{
    /** @var array<string, list<string>> family => web paths */
    public const array FILES = [
        'themix' => [
            '/fonts/themix/TheMix_LT_400.woff2',
            '/fonts/themix/TheMix_LT_700.woff2',
        ],
        'skautbold' => [
            '/fonts/skautbold/skaut-bold-webfont.woff2',
        ],
        'Montserrat' => [
            '/fonts/montserrat/montserrat-v31-latin.woff2',
            '/fonts/montserrat/montserrat-v31-latin-ext.woff2',
        ],
        'Font Awesome' => [
            '/vendor/fontawesome-free-5.8.1/css/all.min.css',
            '/vendor/fontawesome-free-5.8.1/webfonts/fa-solid-900.woff2',
            '/vendor/fontawesome-free-5.8.1/webfonts/fa-regular-400.woff2',
            '/vendor/fontawesome-free-5.8.1/webfonts/fa-brands-400.woff2',
        ],
    ];

    /**
     * The files of the given families, in their order, each once. Family names match
     * case-insensitively, as in CSS, so `'montserrat'` is Montserrat. A family the table
     * does not know (a system font) has nothing to keep and is skipped.
     *
     * @param list<string> $families
     * @return list<string>
     */
    public static function files(array $families): array
    {
        $table = array_change_key_case(self::FILES, CASE_LOWER);
        $files = [];
        foreach ($families as $family) {
            foreach ($table[strtolower($family)] ?? [] as $path) {
                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
    }
}
