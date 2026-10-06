<?php

declare(strict_types=1);

namespace App\Http;

use App\EventConfig;
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
    private const EVENT_FILES = ['site.webmanifest', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png'];

    /**
     * @param list<string> $menuRoutes route names of the tab bar, in its order
     * @return array{version: string, documents: list<string>, assets: list<string>, optional: list<string>}
     */
    public static function build(EventConfig $event, RouteParserInterface $routes, AssetVersion $versions, array $menuRoutes, string $docroot): array
    {
        $documents = [$routes->urlFor('homepage')];
        foreach ($menuRoutes as $name) {
            $documents[] = $routes->urlFor($name);
        }
        $documents[] = $routes->urlFor('profile');
        $documents[] = $routes->urlFor('offline');

        $scripts = ['style.css', 'app.js', 'shell.js', 'push.js'];
        // the layout loads programs.js only for an event with the Program screen
        if ($event->isEnabled('programs')) {
            $scripts[] = 'programs.js';
        }
        $assets = [];
        foreach ($scripts as $file) {
            $assets[] = '/' . $versions->url($file);
        }

        $files = [];
        foreach (self::EVENT_FILES as $name) {
            $files[] = 'events/' . $event->slug . '/' . $name;
        }
        foreach ((array) $event->get('assets', []) as $path) {
            if (is_string($path) && $path !== '') {
                $files[] = $path;
            }
        }
        $homepage = $event->get('homepage');
        if (is_array($homepage) && is_string($homepage['footerLogo'] ?? null) && $homepage['footerLogo'] !== '') {
            $files[] = $homepage['footerLogo'];
        }
        // the fonts and icons the event renders with, so an installed app keeps its look offline
        foreach (Fonts::files($event->fontFamilies()) as $font) {
            $files[] = $font;
        }
        $manifest = json_decode((string) @file_get_contents($docroot . '/events/' . $event->slug . '/site.webmanifest'), true);
        foreach (is_array($manifest) && is_array($manifest['icons'] ?? null) ? $manifest['icons'] : [] as $icon) {
            if (is_string($icon['src'] ?? null)) {
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
        $handbook = $event->get('handbook');
        if ($event->isEnabled('handbook') && is_string($handbook['file'] ?? null) && is_file($docroot . '/' . $handbook['file'])) {
            $optional[] = $routes->urlFor('handbook-download');
        }

        return [
            'version' => self::version(array_values(array_unique($documents)), $assets, $optional),
            'documents' => array_values(array_unique($documents)),
            'assets' => $assets,
            'optional' => $optional,
        ];
    }

    /**
     * First 8 hex of sha256 over the three lists, each sorted, one URL per line and an
     * empty line between the lists. The documents and the optional entries count as much
     * as the assets: a tab enabled or a handbook added is a new list, which the worker
     * picks up only through a new version.
     *
     * @param list<string> $documents
     * @param list<string> $assets
     * @param list<string> $optional
     */
    public static function version(array $documents, array $assets, array $optional): string
    {
        sort($documents);
        sort($assets);
        sort($optional);

        return substr(hash('sha256', implode("\n", [...$documents, '', ...$assets, '', ...$optional])), 0, 8);
    }
}
