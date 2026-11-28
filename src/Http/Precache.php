<?php

declare(strict_types=1);

namespace App\Http;

use App\EventConfig;
use App\Module\MenuItem;
use Slim\Interfaces\RouteParserInterface;

/**
 * What the service worker keeps for an event (www/sw.js install), served as
 * /<slug>/precache.json. The worker never hardcodes a URL: the screens come from the
 * routes, the asset URLs carry the same content hashes the layout prints, and the
 * version changes exactly when an asset's content does — or, for the fonts under
 * /fonts/ and /vendor/, which never change in place, when the event's set of them does.
 */
final class Precache
{
    /** Linked by the layout for every event, under www/events/<slug>/. */
    private const array EVENT_FILES = ['site.webmanifest', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png'];

    /**
     * @param list<string> $stylesheets the core sheets the layout links, in its order (Modules::sheets())
     * @param list<MenuItem> $menu the tab bar, in its order, Domů first
     * @param ?string $imageOrigin the program provider's origin (Environment::programOrigin()), the
     *                             one cross-origin source whose photos the worker keeps; null for `local`
     * @return array{version: string, documents: list<string>, assets: list<string>, optional: list<string>, aliases: array<string, string>, imageOrigin: ?string}
     */
    public static function build(EventConfig $event, RouteParserInterface $routes, AssetVersion $versions, ScriptVersion $scripts, array $stylesheets, array $menu, string $docroot, ?string $imageOrigin = null): array
    {
        // every tab, Domů first and Profil last, then the offline page; a tab's other addresses
        // (MenuItem::$aliases: Program's and Místa's views, Informace's Zprávy) are no
        // documents of their own: the worker finds them under the tab's own copy
        $documents = [];
        $aliases = [];
        foreach ($menu as $item) {
            $screen = $routes->urlFor($item->route);
            $documents[] = $screen;
            foreach ($item->aliases as $alias) {
                $aliases[$routes->urlFor($alias)] = $screen;
            }
        }
        $documents[] = $routes->urlFor('offline');

        // the core sheets the layout links for this event: those of its registered modules
        // only, not every file under www/css/
        $assets = [];
        foreach ($stylesheets as $sheet) {
            $assets[] = '/' . $versions->url($sheet);
        }
        // every script of the www/js/ tree, for every event, under the tree's one version:
        // the few an event without the Program or the Mapa screen never loads cost a few
        // kilobytes, and a list that follows the tree cannot miss a file a script imports
        foreach ($scripts->files() as $file) {
            $assets[] = $scripts->url($file);
        }
        $stylesheet = $event->assets->stylesheet;
        if ($stylesheet !== null && $stylesheet !== '') {
            // versioned so a changed sheet reaches installed apps
            $assets[] = '/' . $versions->url($stylesheet);
        }

        $files = [];
        foreach (self::EVENT_FILES as $name) {
            $files[] = 'events/' . $event->slug . '/' . $name;
        }
        foreach ($event->assets->paths() as $path) {
            $files[] = $path;
        }
        $footerLogo = $event->homepage->footerLogo;
        if ($footerLogo !== null && $footerLogo !== '') {
            $files[] = $footerLogo;
        }
        $plan = $event->map?->image;
        if ($plan !== null && $plan !== '') {
            // the plan is the map that works offline, so it is part of the offline copy
            $files[] = $plan;
        }
        // the fonts and icons the event renders with, so an installed app keeps its look offline
        foreach (Fonts::files($event->fontFamilies()) as $font) {
            $files[] = $font;
        }
        $manifest = json_decode((string) @file_get_contents($docroot . '/events/' . $event->slug . '/site.webmanifest'), true);
        foreach (is_array($manifest) && is_array($manifest['icons'] ?? null) ? $manifest['icons'] : [] as $icon) {
            if (is_array($icon) && is_string($icon['src'] ?? null)) {
                $files[] = $icon['src'];
            }
        }
        foreach ($files as $file) {
            $path = '/' . ltrim($file, '/');
            // one 404 fails the worker's whole install, so a missing file is left out
            if (is_file($docroot . $path)) {
                $assets[] = $path;
            }
        }
        $assets = array_values(array_unique($assets));

        $optional = [];
        $handbook = $event->handbook;
        if ($event->isEnabled('handbook') && $handbook !== null && is_file($docroot . '/' . $handbook->file)) {
            $optional[] = $routes->urlFor('handbook-download');
        }

        return [
            'version' => self::version(array_values(array_unique($documents)), $assets, $optional, $aliases, $imageOrigin),
            'documents' => array_values(array_unique($documents)),
            'assets' => $assets,
            'optional' => $optional,
            'aliases' => $aliases,
            'imageOrigin' => $imageOrigin,
        ];
    }

    /**
     * First 8 hex of sha256 over the four lists, each sorted, one entry per line and an
     * empty line between the lists; an alias is the line "<alias> <screen>". The documents,
     * the optional entries and the aliases count as much as the assets: a tab enabled, a
     * handbook added or a view given an address of its own is a new list, which the worker
     * picks up only through a new version. An image origin follows as an empty line and
     * "image <origin>", so a provider moved to another origin is a new list too; without one
     * the text, and so the version, is what it was before the list had the field.
     *
     * @param list<string> $documents
     * @param list<string> $assets
     * @param list<string> $optional
     * @param array<string, string> $aliases alias URL => its screen's URL
     */
    public static function version(array $documents, array $assets, array $optional, array $aliases = [], ?string $imageOrigin = null): string
    {
        sort($documents);
        sort($assets);
        sort($optional);
        $lines = [];
        foreach ($aliases as $alias => $screen) {
            $lines[] = $alias . ' ' . $screen;
        }
        sort($lines);

        $text = implode("\n", [...$documents, '', ...$assets, '', ...$optional, '', ...$lines]);
        if ($imageOrigin !== null) {
            $text .= "\n\nimage " . $imageOrigin;
        }

        return substr(hash('sha256', $text), 0, 8);
    }
}
