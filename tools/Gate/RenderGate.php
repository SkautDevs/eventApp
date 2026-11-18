<?php

declare(strict_types=1);

namespace Tools\Gate;

use Symfony\Component\Panther\ProcessManager\WebServerManager;
use Tests\Browser\BrowserTestCase;

/**
 * The render gate: a fixed list of screen states in Chrome, each dumped as every element's
 * box and computed style (tools/Gate/dump.js) and as its DOM, so a reference tree and a
 * candidate tree can be diffed (Comparison) and a CSS or JS refactor shown to change
 * nothing visible.
 *
 * It drives the browser tests' Chrome the way bin/baseline.php does, at their 412 × 915
 * phone. The app is served under a frozen clock (libfaketime, as tools/Gate/Server.php
 * does for the HTML gate) through tools/Gate/front.php, so the fixture events under
 * tests/fixtures/events/ are served beside the shipped ones; the server is restarted for
 * each instant, and Chrome's clock for the page scripts (window.pgClock) is pinned to the
 * same instant. Every state starts from a browser with no cookie, no worker, no cache
 * storage and no local or session storage, and is a fresh full-document visit.
 *
 * What would otherwise be a moment of the browser's or the network's own is pinned:
 * the warm-up (coldScreens()), the install offer and the map iframe's load
 * (instrument()), a cookie set by a request still in flight from the state before
 * (clean()), the worker the pairing left behind (dropWorker()) and the scripts' race
 * with the fonts (SCRIPT_DELAY_MS).
 *
 * A known blind spot follows from that last one: the server holds every script back, so
 * the fonts, the images and the map's iframe always arrive before any script runs. An
 * effect that shows only when a script runs first (a measurement taken in the fallback
 * font, a listener attached after the event it waits for) is invisible to this gate.
 */
final class RenderGate extends BrowserTestCase
{
    private const string FAKETIME_LIBRARY = '/usr/lib/faketime/libfaketime.so.1';

    private const string TIMEZONE = 'Europe/Prague';

    /** The value of ADMIN_TOKEN_<SLUG> the server sets for every event. */
    private const string ADMIN_TOKEN = 'gate';

    /** A page whose data-fetched-at is the request time on the Prague clock. */
    private const string CLOCK_PAGE = '/obrok27/offline';

    /** A static file of the test origin: a document with no script, where the session storage is cleared. */
    private const string BLANK_PAGE = '/style.css';

    /** The pairing poll, blocked for the state that shows the code. */
    private const string POLL_PATTERN = '*/profil/propojit/stav';

    /** The open sheet's write button, as a JS expression (undefined when there is none). */
    private const string SHEET_WRITE = 'Array.from(document.querySelectorAll("[data-screen]:not([hidden]) [data-pg-sheet].is-open form[data-pg-write] button"))'
        . '.find(button => button.getClientRects().length > 0)';

    /**
     * How long the server holds every script it serves as a file. The Program screen
     * measures its day chips when its script runs, and a strip wider than the phone (the
     * 240px viewport) is scrolled to the current chip by what the chips measured then: in
     * the event's font when that had arrived, in the fallback when it had not, a moment
     * of the network's own (tools/Gate/front.php). Held, the scripts run once the fonts
     * the first layout asked for are in, every time.
     */
    private const int SCRIPT_DELAY_MS = 500;

    /** The seconds a settle condition may take. */
    private const int SETTLE_SECONDS = 30;

    /**
     * What is dumped, in order: an event at an instant, its states (all of STATES when
     * left out), its identities (both when left out), light only or both modes where the
     * event declares a dark set, and a viewport other than the default one.
     *
     * @var list<array{instant: string, slug: string, states?: list<string>, identities?: list<string>, light?: bool, viewport?: array{int, int}}>
     */
    private const array RUNS = [
        ['instant' => '2026-09-17 10:00:00', 'slug' => 'korbo26'],
        ['instant' => '2027-04-10 12:00:00', 'slug' => 'obrok27', 'states' => ['home']],
        ['instant' => '2027-04-10 12:00:00', 'slug' => 'navigamus27', 'states' => ['home']],
        ['instant' => '2027-05-20 10:00:00', 'slug' => 'obrok19'],
        ['instant' => '2027-05-20 10:00:00', 'slug' => 'obrok27'],
        ['instant' => '2027-05-20 10:00:00', 'slug' => 'miquik26'],
        ['instant' => '2027-05-20 10:00:00', 'slug' => 'programs', 'identities' => ['out'], 'light' => true],
        ['instant' => '2027-05-20 10:00:00', 'slug' => 'map-plan', 'identities' => ['out'], 'light' => true],
        // the stylesheet's max-height: 520px and max-width: 249px blocks, which 412 × 915 never reaches
        ['instant' => '2027-05-20 10:00:00', 'slug' => 'obrok27', 'states' => ['home', 'programs-timeline', 'programs-sheet'], 'identities' => ['out'], 'light' => true, 'viewport' => [412, 500]],
        ['instant' => '2027-05-20 10:00:00', 'slug' => 'obrok27', 'states' => ['home', 'programs-timeline', 'programs-sheet'], 'identities' => ['out'], 'light' => true, 'viewport' => [240, 700]],
        ['instant' => '2027-06-04 15:00:00', 'slug' => 'navigamus27'],
    ];

    /**
     * Every state: the identities it is dumped for and the feature it needs (null: none).
     *
     * @var array<string, array{list<string>, string|null}>
     */
    private const array STATES = [
        'home' => [['out', 'paired'], null],
        'programs-timeline' => [['out', 'paired'], 'programs'],
        'programs-overview' => [['out', 'paired'], 'programs'],
        'programs-list' => [['out', 'paired'], 'programs'],
        'programs-sheet' => [['out', 'paired'], 'programs'],
        'programs-written' => [['paired'], 'programs'],
        'map-google' => [['out'], 'map'],
        'map-plan' => [['out'], 'map'],
        'info' => [['out', 'paired'], 'info'],
        'profil' => [['out', 'paired'], null],
        'profil-code' => [['out'], null],
        'admin' => [['out'], 'push'],
        'offline' => [['out'], null],
        '404' => [['out'], null],
        'walk' => [['paired'], null],
    ];

    /**
     * The page is settled: loaded, fonts loaded, every Program screen wired, every image
     * of a visible screen complete, every map iframe that is rendered loaded (see
     * instrument()), and nothing animating.
     */
    private const string SETTLED = <<<'JS'
        if (document.readyState !== 'complete' || document.fonts.status !== 'loaded') {
            return false;
        }
        const visible = Array.from(document.querySelectorAll('[data-screen]')).filter(screen => !screen.hidden);
        const scopes = visible.length > 0 ? visible : [document];
        return scopes.every(scope =>
            Array.from(scope.querySelectorAll('[data-pg-root]')).every(root => root.dataset.pgReady === '1')
            && Array.from(scope.querySelectorAll('img')).every(img => img.complete)
            && Array.from(scope.querySelectorAll('[data-map] iframe')).every(iframe =>
                iframe.getClientRects().length === 0 || iframe.__gateLoaded === true)
        ) && document.getAnimations().length === 0;
        JS;

    private static ?WebServerManager $gateServer = null;

    /** The clock the server is started with, Prague time. */
    private static string $instant = '';

    /** The temp directory of event symlinks the server reads (GATE_EVENTS_DIR). */
    private static string $eventsDir = '';

    private static int $count = 0;

    /** Dumps every state under $out (which it replaces) and returns the number of files written. */
    public static function dump(string $out): int
    {
        $mode = self::mode();
        if ($mode === null) {
            throw new \RuntimeException('No chromedriver on PATH and no PANTHER_SELENIUM_URL: run the render gate through the compose `test` service');
        }
        if (!is_file(self::FAKETIME_LIBRARY)) {
            throw new \RuntimeException('libfaketime is missing: run the render gate with `apk add --no-cache libfaketime tzdata`');
        }
        Server::remove($out);
        self::$count = 0;
        self::$eventsDir = self::linkEvents();
        try {
            self::$browser = $mode === 'remote' ? self::remoteClient() : self::localClient();
            self::$browser->manage()->timeouts()->setScriptTimeout(60);
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
            self::instrument();
            $instants = array_values(array_unique(array_column(self::RUNS, 'instant')));
            sort($instants, SORT_STRING);
            foreach ($instants as $instant) {
                self::atInstant($out, $instant);
            }
        } finally {
            self::$browser?->quit();
            self::$browser = null;
            self::unpinClock();
            self::stopServer();
            self::removeDatabase();
            self::removeSessions();
            self::removeStubState();
            Server::remove(self::$eventsDir);
        }

        return self::$count;
    }

    /**
     * Two moments of the browser's own, taken out of the page before any of its scripts
     * runs, on every document of the session:
     *
     *  - headless Chrome offers the install when it likes, and www/js/shell/install.js shows the
     *    install button when it does, before the dump on one run and after it on the
     *    next: the offer is stopped, so the button stays in its markup state (hidden;
     *    settle() still pins it, as bin/baseline.php does);
     *  - an iframe's load is recorded on the element (__gateLoaded, a property, not an
     *    attribute), so settle() can wait for the map's iframe whether or not map/offline.js
     *    was listening yet when it fired.
     */
    private static function instrument(): void
    {
        self::devTools()->execute('Page.addScriptToEvaluateOnNewDocument', [
            'source' => 'window.addEventListener("beforeinstallprompt", function (event) { event.stopImmediatePropagation(); }, true);'
                . 'document.addEventListener("load", function (event) { if (event.target && event.target.tagName === "IFRAME") { event.target.__gateLoaded = true; } }, true);',
        ]);
    }

    /** Every run at one instant, against a server started for it. */
    private static function atInstant(string $out, string $instant): void
    {
        self::$instant = $instant;
        // a fresh message log per instant: each instant's holds only its own seed
        self::removeDatabase();
        self::startServer();
        try {
            self::proveClock($instant);
            self::unpinClock();
            // with its Prague offset: pinClock() parses a bare string in this process's zone
            self::pinClock((new \DateTimeImmutable($instant, new \DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM));
            // the warm-up inserts hidden screens at a moment of its own
            self::coldScreens();
            $seeded = [];
            foreach (self::RUNS as $run) {
                if ($run['instant'] !== $instant) {
                    continue;
                }
                $slug = $run['slug'];
                if (!isset($seeded[$slug])) {
                    // the .news cards, a sheet's Oznámení and the admin log, once per event
                    // and instant (the log is emptied before each instant's server starts)
                    (new HtmlGate())->seedMessages('http://127.0.0.1:' . self::PORT, $slug);
                    $seeded[$slug] = true;
                }
                self::runEvent($out, $run);
            }
        } finally {
            self::stopServer();
        }
    }

    /** @param array{instant: string, slug: string, states?: list<string>, identities?: list<string>, light?: bool, viewport?: array{int, int}} $run */
    private static function runEvent(string $out, array $run): void
    {
        $slug = $run['slug'];
        $config = self::config($slug);
        $features = is_array($config['features'] ?? null) ? $config['features'] : [];
        $modes = ($run['light'] ?? false) || !isset($config['roles']['dark']) ? ['light'] : ['light', 'dark'];
        $viewport = $run['viewport'] ?? null;
        if ($viewport !== null) {
            self::overrideViewport($viewport[0], $viewport[1]);
        }
        try {
            foreach ($modes as $mode) {
                self::emulateMode($mode);
                foreach ($run['states'] ?? array_keys(self::STATES) as $state) {
                    // PHP turns the key '404' into an int
                    $state = (string) $state;
                    [$identities, $feature] = self::STATES[$state];
                    if ($feature !== null && !in_array($feature, $features, true)) {
                        continue;
                    }
                    foreach ($identities as $identity) {
                        if (!in_array($identity, $run['identities'] ?? ['out', 'paired'], true)) {
                            continue;
                        }
                        $name = $state . '-' . $identity . ($viewport === null ? '' : '-' . $viewport[0] . 'x' . $viewport[1]);
                        $dir = sprintf('%s/%s/%s/%s', $out, $slug, $run['instant'], $mode);
                        try {
                            self::state($dir, $name, $slug, $state, $identity === 'paired');
                        } catch (\Throwable $e) {
                            throw new \RuntimeException(sprintf('%s %s %s %s: %s', $run['instant'], $slug, $mode, $name, $e->getMessage()), 0, $e);
                        }
                    }
                }
            }
        } finally {
            if ($viewport !== null) {
                self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
            }
        }
    }

    /** One state from a clean browser; writes its two files unless the event has nothing to show for it. */
    private static function state(string $dir, string $name, string $slug, string $state, bool $paired): void
    {
        self::clean();
        self::resetStubState($slug);
        if ($paired) {
            self::pair($slug);
            self::dropWorker();
        }
        $base = '/' . $slug;
        switch ($state) {
            case 'home':
                self::visitSettled($base . '/');
                break;
            case 'programs-timeline':
                self::visitSettled($base . '/programy');
                break;
            case 'programs-overview':
            case 'programs-list':
                $view = $state === 'programs-overview' ? 'overview' : 'list';
                self::visitSettled($base . '/programy');
                self::tap('[data-screen]:not([hidden]) [data-pg-view="' . $view . '"]');
                self::waitFor('return document.querySelector(\'[data-screen]:not([hidden]) [data-pg-root][data-view="' . $view . '"]\') !== null;');
                self::settle();
                break;
            case 'programs-sheet':
            case 'programs-written':
                self::sheet($slug);
                if ($state === 'programs-written' && !self::tapWrite()) {
                    return;
                }
                break;
            case 'map-google':
                self::visitSettled($base . '/mapa');
                break;
            case 'map-plan':
                self::visitSettled($base . '/mapa');
                $tab = '[data-screen]:not([hidden]) [role="tab"][data-map-view="plan"]';
                if (!self::script('return document.querySelector(arguments[0]) !== null;', [$tab])) {
                    return;
                }
                self::tap($tab);
                self::waitFor('return document.querySelector(\'[data-screen]:not([hidden]) [data-map-root][data-view="plan"]\') !== null;');
                self::settle();
                break;
            case 'info':
                self::visitSettled($base . '/informace');
                break;
            case 'profil':
                self::visitSettled($base . '/profil');
                break;
            case 'profil-code':
                self::pairingCode($slug);
                break;
            case 'admin':
                self::visitSettled($base . '/admin/notify?token=' . self::ADMIN_TOKEN);
                break;
            case 'offline':
                self::visitSettled($base . '/offline');
                break;
            case '404':
                self::visitSettled($base . '/nic-takoveho');
                break;
            case 'walk':
                self::walk($slug);
                break;
            default:
                throw new \LogicException('No state ' . $state);
        }
        self::save($dir, $name);
        // the toast leaves after 6 s on display: a dump taken after that missed what it is for
        if ($state === 'programs-written' && self::script('return document.querySelector("[data-toast]") !== null;') !== true) {
            throw new \RuntimeException('The toast was gone by the time programs-written was dumped');
        }
    }

    /**
     * No cookie, no worker, no cache storage, no local or session storage: the blank page
     * first, a document of the origin that runs no script, so none of the state before is
     * left running; then CDP; then the session storage, which is the tab's and which only
     * a document of the origin can clear.
     *
     * The server's sessions go too. A worker killed half-way through its fill can still
     * have a page request in flight, and its answer, arriving after the cookies were
     * cleared, sets the sliding session cookie (Session::refreshCookie()) of the identity
     * before: the next state was then served paired at a moment of the request's own.
     * With its session file gone, such a cookie reads nothing.
     */
    private static function clean(): void
    {
        self::visit(self::BLANK_PAGE);
        $devTools = self::devTools();
        $devTools->execute('Storage.clearDataForOrigin', ['origin' => self::baseUri(), 'storageTypes' => 'all']);
        $devTools->execute('Network.clearBrowserCookies');
        foreach (glob(parent::serverEnvironment()['SESSION_PATH'] . '/sess_*') ?: [] as $file) {
            @unlink($file);
        }
        self::script('sessionStorage.clear(); localStorage.clear();');
    }

    /**
     * The worker Profil registered during the pairing filled its cache logged out, at a
     * moment of its own; a screen the loader then took from that cache was morphed into
     * the paired one whenever the worker's background fetch told it so, or not. Gone, the
     * paired state starts like every other: no worker, which the state's first page
     * registers and fills for the identity in the cookie.
     */
    private static function dropWorker(): void
    {
        self::visit(self::BLANK_PAGE);
        self::devTools()->execute('Storage.clearDataForOrigin', ['origin' => self::baseUri(), 'storageTypes' => 'service_workers,cache_storage']);
    }

    private static function emulateMode(string $mode): void
    {
        self::devTools()->execute('Emulation.setEmulatedMedia', [
            'media' => '',
            'features' => [
                ['name' => 'prefers-color-scheme', 'value' => $mode],
                // Chrome runs with --force-prefers-reduced-motion; restated, so the emulation keeps it
                ['name' => 'prefers-reduced-motion', 'value' => 'reduce'],
            ],
        ]);
    }

    private static function visitSettled(string $path): void
    {
        self::visit($path);
        self::settle();
    }

    /** See SETTLED; then two animation frames, and the install offer pinned hidden. */
    private static function settle(): void
    {
        $deadline = microtime(true) + self::SETTLE_SECONDS;
        do {
            self::asyncScript('const done = arguments[arguments.length - 1]; document.fonts.ready.then(() => done(true));');
            self::waitFor(self::SETTLED, [], self::SETTLE_SECONDS);
            self::asyncScript('const done = arguments[arguments.length - 1]; requestAnimationFrame(() => requestAnimationFrame(() => done(true)));');
            // the two frames may have started a font or an animation: settled is settled after them too
            if (self::script(self::SETTLED)) {
                break;
            }
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('The page did not settle');
            }
        } while (true);
        // as bin/baseline.php does; instrument() already keeps the offer from showing it
        self::script('document.querySelectorAll("[data-install]").forEach(element => { element.hidden = true; });');
    }

    /** The registered programme's sheet (the HTML gate's programme), or the first programme's. */
    private static function sheet(string $slug): void
    {
        $fixtures = self::eventDir($slug) . '/fixtures';
        $registered = is_file($fixtures . '/registered.json') ? json_decode((string) file_get_contents($fixtures . '/registered.json'), true) : null;
        $programmes = json_decode((string) file_get_contents($fixtures . '/programs.json'), true);
        if (!is_array($programmes) || !is_array($programmes[0] ?? null)) {
            throw new \RuntimeException('No programme in ' . $fixtures . '/programs.json');
        }
        $id = is_array($registered) && is_array($registered['1'] ?? null) && is_int($registered['1'][0] ?? null)
            ? $registered['1'][0]
            : (int) $programmes[0]['id'];
        $section = null;
        foreach ($programmes as $programme) {
            if (is_array($programme) && ($programme['id'] ?? null) === $id) {
                $section = $programme['section']['id'] ?? null;
                break;
            }
        }
        if (!is_int($section)) {
            throw new \RuntimeException(sprintf('No section for the programme %d in %s/programs.json', $id, $fixtures));
        }
        self::visit(sprintf('/%s/programy#section-%d-program-%d', $slug, $section, $id));
        self::waitFor('return document.querySelector("[data-screen]:not([hidden]) [data-pg-sheet].is-open") !== null;');
        self::settle();
    }

    /**
     * Taps the open sheet's write (and its confirmation, for an unregister) and waits for
     * the reloaded page: the toast over the reopened sheet. False when the sheet has no
     * write to tap (logged out, or no registration window open).
     */
    private static function tapWrite(): bool
    {
        if (self::script('return ' . self::SHEET_WRITE . ' !== undefined;') !== true) {
            return false;
        }
        self::script('window.__gateBefore = true;');
        $confirm = self::script('return ' . self::SHEET_WRITE . '.hasAttribute("data-confirm");') === true;
        self::script(self::SHEET_WRITE . '.click();');
        if ($confirm) {
            // shell/confirm.js's two taps: the first asks, the second submits
            self::waitFor('return ' . self::SHEET_WRITE . '.hasAttribute("data-confirming");');
            self::script(self::SHEET_WRITE . '.click();');
        }
        self::waitFor(
            'return window.__gateBefore !== true && document.readyState === "complete"'
            . ' && document.querySelector("[data-screen]:not([hidden]) [data-pg-sheet].is-open") !== null'
            . ' && document.querySelector("[data-toast]") !== null;',
            [],
            20,
        );
        self::settle();

        return true;
    }

    /** Profil logged out with the pairing code up: the poll blocked, so the local source never approves it. */
    private static function pairingCode(string $slug): void
    {
        $devTools = self::devTools();
        $devTools->execute('Network.enable');
        $devTools->execute('Network.setBlockedURLs', ['urls' => [self::POLL_PATTERN]]);
        try {
            self::visitSettled('/' . $slug . '/profil');
            self::script('window.__gateBefore = true;');
            self::tap('[data-screen="/' . $slug . '/profil"] form[action="/' . $slug . '/profil/propojit"] button[type="submit"]');
            self::waitFor(
                'return window.__gateBefore !== true && document.readyState === "complete"'
                . ' && document.querySelector("[data-screen]:not([hidden]) [data-pairing-poll]") !== null;',
                [],
                20,
            );
            self::settle();
        } finally {
            $devTools->execute('Network.setBlockedURLs', ['urls' => []]);
        }
    }

    /** Domů, then every tab-bar link in order, then the profile link, each settled. */
    private static function walk(string $slug): void
    {
        self::visitSettled('/' . $slug . '/');
        $links = self::script('return Array.from(document.querySelectorAll(".tabbar a[href]")).map(link => link.getAttribute("href"));');
        if (!is_array($links) || $links === []) {
            throw new \RuntimeException('The tab bar has no links');
        }
        $targets = [];
        foreach ($links as $href) {
            $targets[] = ['.tabbar a[href="' . $href . '"]', (string) $href];
        }
        $targets[] = ['.appbar-profile', (string) self::script('return document.querySelector(".appbar-profile").getAttribute("href");')];
        foreach ($targets as [$selector, $href]) {
            self::tap($selector);
            self::waitFor(
                'const screen = document.querySelector("[data-screen]:not([hidden])");'
                . ' return document.readyState === "complete" && !document.documentElement.hasAttribute("data-loading")'
                . ' && screen !== null && screen.dataset.screen === arguments[0];',
                [$href],
                20,
            );
            self::settle();
        }
    }

    /** Writes the state's two files: the style dump and the DOM. */
    private static function save(string $dir, string $name): void
    {
        $script = trim((string) file_get_contents(__DIR__ . '/dump.js'));
        $style = self::script('return (' . rtrim($script, ';') . ');');
        $dom = self::script('return document.documentElement.outerHTML;');
        if (!is_string($style) || $style === '' || !is_string($dom)) {
            throw new \RuntimeException('The page returned no dump');
        }
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        foreach ([$name . '.style.txt.gz' => $style, $name . '.dom.html.gz' => Normalizer::normalize($dom)] as $file => $text) {
            if (file_put_contents($dir . '/' . $file, gzencode($text, 9)) === false) {
                throw new \RuntimeException('Cannot write ' . $dir . '/' . $file);
            }
            self::$count++;
        }
        fwrite(STDERR, '.');
    }

    /**
     * The browser tests' environment, plus the frozen clock (libfaketime reads FAKETIME as
     * Prague time with tzdata installed), the gate's events directory, its admin token and
     * the script delay (SCRIPT_DELAY_MS).
     *
     * @return array<string, string>
     */
    protected static function serverEnvironment(): array
    {
        $env = [
            ...parent::serverEnvironment(),
            'LD_PRELOAD' => self::FAKETIME_LIBRARY,
            'FAKETIME' => self::$instant,
            'TZ' => self::TIMEZONE,
            'VAPID_SUBJECT' => 'mailto:gate@example.invalid',
            'GATE_EVENTS_DIR' => self::$eventsDir,
            'GATE_SCRIPT_DELAY_MS' => (string) self::SCRIPT_DELAY_MS,
            // the delayed scripts must not hold up the fonts and pages behind them
            'PHP_CLI_SERVER_WORKERS' => '16',
        ];
        foreach (glob(self::$eventsDir . '/*') ?: [] as $dir) {
            $env['ADMIN_TOKEN_' . strtoupper(str_replace('-', '_', basename($dir)))] = self::ADMIN_TOKEN;
        }

        return $env;
    }

    /**
     * The browser tests' server, routed through tools/Gate/front.php instead of
     * bin/router.php: the same files served as they are, the app booted against the
     * gate's events directory, which also holds the fixture events.
     */
    protected static function startServer(): void
    {
        if (self::$gateServer !== null) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $server = new WebServerManager(
            $root . '/www',
            // Chrome in the selenium container comes in over the compose network
            self::env('BROWSER_TEST_HOST') === '' ? '127.0.0.1' : '0.0.0.0',
            self::PORT,
            $root . '/tools/Gate/front.php',
            '/',
            static::serverEnvironment(),
        );
        $server->start();
        self::$gateServer = $server;
    }

    /** Returns only once nothing answers on the port: PHP_CLI_SERVER_WORKERS forks workers. */
    protected static function stopServer(): void
    {
        if (self::$gateServer === null) {
            return;
        }
        self::$gateServer->quit();
        self::$gateServer = null;
        $deadline = microtime(true) + 5;
        while (($socket = @fsockopen('127.0.0.1', self::PORT, $errno, $error, 0.2)) !== false) {
            fclose($socket);
            if (microtime(true) > $deadline) {
                throw new \RuntimeException(sprintf('The gate server still answers on port %d after it was stopped', self::PORT));
            }
            usleep(100_000);
        }
    }

    /** Aborts unless the app sees $instant on the Prague clock (no tzdata: libc reads it as UTC). */
    private static function proveClock(string $instant): void
    {
        $expected = (new \DateTimeImmutable($instant, new \DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM);
        $html = @file_get_contents('http://127.0.0.1:' . self::PORT . self::CLOCK_PAGE, false, stream_context_create(['http' => ['ignore_errors' => true]]));
        $seen = is_string($html) && preg_match('/data-fetched-at="([^"]*)"/', $html, $m) === 1 ? $m[1] : null;
        if ($seen !== $expected) {
            throw new \RuntimeException(sprintf('The gate server\'s clock is not frozen at %s: %s says %s (is tzdata installed?)', $expected, self::CLOCK_PAGE, $seen ?? 'nothing'));
        }
    }

    /** A temp directory of symlinks to every event under events/ and tests/fixtures/events/. */
    private static function linkEvents(): string
    {
        $dir = sys_get_temp_dir() . '/gate-render-events-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o700)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        foreach (self::eventSources() as $source) {
            foreach (glob($source . '/*', GLOB_ONLYDIR) ?: [] as $event) {
                if (!symlink($event, $dir . '/' . basename($event))) {
                    Server::remove($dir);

                    throw new \RuntimeException(sprintf('Cannot link the event %s into %s', basename($event), $dir));
                }
            }
        }

        return $dir;
    }

    /** @return list<string> */
    private static function eventSources(): array
    {
        $root = dirname(__DIR__, 2);

        return [$root . '/events', $root . '/tests/fixtures/events'];
    }

    private static function eventDir(string $slug): string
    {
        foreach (self::eventSources() as $source) {
            if (is_file($source . '/' . $slug . '/config.php')) {
                return $source . '/' . $slug;
            }
        }

        throw new \RuntimeException('No event ' . $slug);
    }

    /** @return array<string, mixed> */
    private static function config(string $slug): array
    {
        $config = require self::eventDir($slug) . '/config.php';

        return is_array($config) ? $config : [];
    }
}
