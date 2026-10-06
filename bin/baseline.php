<?php

declare(strict_types=1);

/*
 * Re-takes docs/baseline/obrok19/*.png: the reference event's five screens in Chrome at
 * 390 × 844, served by the same PHP server and the same Chrome the browser tests use
 * (tests/Browser/BrowserTestCase), through the stub provider and an empty push database.
 *
 *   docker compose --profile browser run --rm --use-aliases test php bin/baseline.php
 *   docker compose --profile browser stop chrome
 *
 * or, where chromedriver is installed, `php bin/baseline.php`. A round diffs its own
 * screens against these; the freshness line is pinned to 12:00 so a re-take at another
 * time of day is the same picture, and the install button on /profil is pinned hidden.
 *
 * Chrome gets the browser tests' arguments, the host-resolver rule included, so no
 * third-party host resolves: only the Maps iframe is missing. Every font and the icons
 * are self-hosted (www/fonts/, www/vendor/), so the screens wear their real typefaces
 * and it is the same picture on every run. The compose `chrome` service runs the
 * unpinned selenium/standalone-chrome:latest, so an image upgrade can still move pixels
 * (text rendering): re-take the baseline before a round on a new image, not only after it.
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use Tests\Browser\BrowserTestCase;

final class Baseline extends BrowserTestCase
{
    public const SCREENS = [
        'home' => '/obrok19/',
        'novinky' => '/obrok19/novinky',
        'odkazy' => '/obrok19/odkazy',
        'profil' => '/obrok19/profil',
        'programy' => '/obrok19/programy',
    ];

    private const WIDTH = 390;

    private const HEIGHT = 844;

    protected static function windowSize(): string
    {
        return self::WIDTH . ',' . self::HEIGHT;
    }

    public static function shoot(string $dir): int
    {
        $mode = self::mode();
        if ($mode === null) {
            fwrite(STDERR, "No chromedriver on PATH and no PANTHER_SELENIUM_URL; run it through the compose `test` service.\n");

            return 1;
        }

        self::removeDatabase();
        self::startServer();
        try {
            self::$browser = $mode === 'remote' ? self::remoteClient() : self::localClient();
            self::$browser->manage()->timeouts()->setScriptTimeout(30);
            // headless Chrome clamps its window to 500px, so --window-size alone is not 390
            self::overrideViewport(self::WIDTH, self::HEIGHT);
            foreach (self::SCREENS as $name => $path) {
                self::visit($path);
                self::waitFor('return document.readyState === "complete";');
                if ($name === 'programy') {
                    self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
                }
                self::asyncScript('const done = arguments[arguments.length - 1]; document.fonts.ready.then(() => done(true));');
                $size = self::script('return [window.innerWidth, window.innerHeight];');
                if ($size !== [self::WIDTH, self::HEIGHT]) {
                    throw new \RuntimeException(sprintf('The viewport is %dx%d, not %dx%d', $size[0], $size[1], self::WIDTH, self::HEIGHT));
                }
                // Chrome offers the install at a moment of its own, so the button on /profil
                // is pinned to its markup state (hidden), as the freshness line is to 12:00
                self::script('document.querySelectorAll("[data-install]").forEach(p => { p.hidden = true; });');
                self::script('document.querySelectorAll("[data-freshness]").forEach(p => { if (p.textContent !== "") { p.textContent = "aktualizováno 12:00"; } });');
                $file = $dir . '/' . $name . '.png';
                self::$browser->takeScreenshot($file);
                // the compose `test` container runs as root; the file belongs to whoever owns the directory
                if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    chown($file, (int) fileowner($dir));
                    chgrp($file, (int) filegroup($dir));
                }
                fwrite(STDOUT, $name . ".png\n");
            }
        } finally {
            self::$browser?->quit();
            self::$browser = null;
            self::stopServer();
            self::removeDatabase();
        }

        return 0;
    }
}

exit(Baseline::shoot(dirname(__DIR__) . '/docs/baseline/obrok19'));
