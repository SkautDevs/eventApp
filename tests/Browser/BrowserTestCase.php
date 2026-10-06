<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\Panther\ProcessManager\WebServerManager;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The app in a real Chrome, driven from PHP — there is no Node in this project. Two ways
 * to get a Chrome:
 *
 *  - local (CI): chromedriver on this machine, PANTHER_CHROME_DRIVER_BINARY or
 *    `chromedriver` on PATH, starts a headless Chrome next to the test;
 *  - remote (the dev compose): PANTHER_SELENIUM_URL names a Selenium server (the
 *    `chrome` service), and BROWSER_TEST_HOST is the name Chrome reaches this
 *    container by (`test`, through `docker compose run --use-aliases`).
 *
 * Either way the app is served by a PHP built-in server this class starts itself, with
 * bin/router.php and an environment of its own (the stub provider, a throwaway push
 * database), so a test can stop it to take the network away — CDP's offline emulation
 * does not reach the service worker's own fetches, and Panther's stopWebServer() would
 * quit the browser too. Without either Chrome every test is skipped, unless
 * PANTHER_NO_SKIP=1 (CI), where that is a failure.
 */
abstract class BrowserTestCase extends PantherTestCase
{
    protected const PORT = 9080;

    /** A phone: the app is laid out as one at every width, and this is its shape. */
    protected const VIEWPORT_WIDTH = 412;

    protected const VIEWPORT_HEIGHT = 915;

    /** The server's PUSH_DB_PATH, relative to the repository root; created by the app, removed here. */
    protected const DATABASE = 'var/browser-test.sqlite';

    private const NO_CHROME = 'No chromedriver on PATH and no PANTHER_SELENIUM_URL — run with --exclude-group browser or install Chrome';

    protected static ?Client $browser = null;

    private static ?WebServerManager $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $mode = self::mode();
        if ($mode === null) {
            if (self::env('PANTHER_NO_SKIP') === '1') {
                self::fail(self::NO_CHROME);
            }
            self::markTestSkipped(self::NO_CHROME);
        }

        self::removeDatabase();
        self::startServer();
        self::$browser = $mode === 'remote' ? self::remoteClient() : self::localClient();
        self::$browser->manage()->timeouts()->setScriptTimeout(30);
        self::ensureViewport();
    }

    public static function tearDownAfterClass(): void
    {
        self::$browser?->quit();
        self::$browser = null;
        self::stopServer();
        self::removeDatabase();
        parent::tearDownAfterClass();
    }

    protected static function visit(string $path): void
    {
        self::$browser->request('GET', $path);
    }

    protected static function script(string $js, array $arguments = []): mixed
    {
        return self::$browser->executeScript($js, $arguments);
    }

    /** The script gets `done` as its last argument and must call it. */
    protected static function asyncScript(string $js, array $arguments = []): mixed
    {
        return self::$browser->executeAsyncScript($js, $arguments);
    }

    /** Polls $js — a function body that `return`s — until it answers something truthy. */
    protected static function waitFor(string $js, array $arguments = [], int $seconds = 10): void
    {
        self::$browser->wait($seconds, 100)->until(
            static fn ($driver): bool => (bool) $driver->executeScript($js, $arguments),
            'Timed out waiting for: ' . $js,
        );
    }

    protected static function tap(string $selector): void
    {
        self::$browser->findElement(WebDriverBy::cssSelector($selector))->click();
    }

    /**
     * Waits until the event's worker is installed (its precache complete), active and in
     * control of the page; returns the name of its cache.
     *
     * Polls for the completion marker — the list itself, stored last in a cache named
     * eventapp-<slug>-<8 hex> — rather than awaiting navigator.serviceWorker.ready:
     * an install that fails (one asset request reset by the built-in server, and fill()
     * deletes its half-filled cache) leaves the worker redundant and `ready` pending
     * forever, which used to surface only as a bare script timeout 30 s later. A worker
     * seen to go redundant fails the test at once, and says so.
     */
    protected static function waitForPrecache(string $slug): string
    {
        $result = self::waitForAsync(<<<'JS'
            const done = arguments[arguments.length - 1];
            const slug = arguments[0];
            const mine = new RegExp('^eventapp-' + slug + '-[0-9a-f]{8}$');
            // a worker seen once is watched: one that goes redundant was a failed install
            const watch = worker => {
                if (!worker) {
                    return;
                }
                window.__precacheSeen = true;
                if (worker.state === 'redundant') {
                    window.__precacheRedundant = true;
                } else if (!worker.__precacheWatched) {
                    worker.__precacheWatched = true;
                    worker.addEventListener('statechange', () => {
                        if (worker.state === 'redundant') {
                            window.__precacheRedundant = true;
                        }
                    });
                }
            };
            navigator.serviceWorker.getRegistration('/' + slug + '/').then(registration => {
                if (registration) {
                    [registration.installing, registration.waiting, registration.active].forEach(watch);
                    if (window.__precacheSeen && !registration.installing && !registration.waiting && !registration.active) {
                        window.__precacheRedundant = true;
                    }
                }
                if (window.__precacheRedundant && !(registration && registration.active)) {
                    return {failed: 'the ' + slug + ' worker went redundant: its install failed'};
                }
                const scope = registration ? registration.scope : null;
                if (scope === null) {
                    return null;
                }
                return caches.keys().then(keys => Promise.all(keys.filter(key => mine.test(key)).map(key =>
                    caches.open(key).then(cache => cache.match(scope + 'precache.json')).then(marker => (marker ? key : null))
                ))).then(complete => complete.filter(Boolean).pop() || null);
            }).then(done, error => done({failed: 'error: ' + error}));
            JS, [$slug], 20);
        if (is_array($result)) {
            self::fail((string) ($result['failed'] ?? 'waitForPrecache: unexpected answer'));
        }
        self::assertIsString($result);
        self::assertMatchesRegularExpression('/^eventapp-' . preg_quote($slug, '/') . '-[0-9a-f]{8}$/', $result);
        self::waitFor('return navigator.serviceWorker.controller !== null;');

        return $result;
    }

    /** Polls an async script (it gets `done` last) until it hands back something truthy. */
    protected static function waitForAsync(string $js, array $arguments = [], int $seconds = 15): mixed
    {
        $deadline = microtime(true) + $seconds;
        do {
            $value = self::asyncScript($js, $arguments);
            if ($value) {
                return $value;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for: ' . $js);
    }

    protected static function baseUri(): string
    {
        return 'http://' . self::host() . ':' . self::PORT;
    }

    protected static function startServer(): void
    {
        if (self::$server !== null) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $server = new WebServerManager(
            $root . '/www',
            // Chrome in the selenium container comes in over the compose network
            self::host() === '127.0.0.1' ? '127.0.0.1' : '0.0.0.0',
            self::PORT,
            $root . '/bin/router.php',
            '/',
            self::serverEnvironment(),
        );
        $server->start();
        // kept only once it answers: a start that throws must leave no half-started server
        // behind for every later class's startServer() to mistake for a running one
        self::$server = $server;
    }

    /** Returns only once nothing answers on the port: PHP_CLI_SERVER_WORKERS forks workers. */
    protected static function stopServer(): void
    {
        if (self::$server === null) {
            return;
        }
        self::$server->quit();
        self::$server = null;

        $deadline = microtime(true) + 5;
        while (($socket = @fsockopen('127.0.0.1', self::PORT, $errno, $error, 0.2)) !== false) {
            fclose($socket);
            if (microtime(true) > $deadline) {
                throw new \RuntimeException(sprintf('The PHP server still answers on port %d after it was stopped', self::PORT));
            }
            usleep(100_000);
        }
    }

    protected static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : '';
    }

    /** 'remote', 'local', or null when there is no Chrome to drive */
    protected static function mode(): ?string
    {
        if (self::env('PANTHER_SELENIUM_URL') !== '') {
            return 'remote';
        }

        return self::chromeDriver() !== null ? 'local' : null;
    }

    private static function chromeDriver(): ?string
    {
        $configured = self::env('PANTHER_CHROME_DRIVER_BINARY');
        if ($configured !== '') {
            return is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder())->find('chromedriver');
    }

    /** The name Chrome reaches the app by: this machine, or this container on the compose network. */
    private static function host(): string
    {
        $host = self::env('BROWSER_TEST_HOST');

        return $host !== '' ? $host : '127.0.0.1';
    }

    /**
     * Passed to the server process explicitly, on top of the inherited environment.
     * Dotenv never overwrites a variable that is already set, so these beat a local .env.
     *
     * @return array<string, string>
     */
    private static function serverEnvironment(): array
    {
        return [
            'APP_DEBUG' => '0',
            'SENTRY_DSN' => '',
            'PROGRAM_PROVIDER_KORBO26' => 'stub',
            'PROGRAM_PROVIDER_OBROK19' => 'stub',
            'PROGRAM_PROVIDER_NAVIGAMUS25' => 'stub',
            'PUSH_DB_PATH' => self::DATABASE,
            // the worker's install fetches a dozen files at once; one PHP worker would queue them
            'PHP_CLI_SERVER_WORKERS' => '4',
            // tests/bootstrap.php's throwaway pair: every event has push and refuses to boot without one
            'VAPID_PUBLIC_KEY' => (string) ($_ENV['VAPID_PUBLIC_KEY'] ?? ''),
            'VAPID_PRIVATE_KEY' => (string) ($_ENV['VAPID_PRIVATE_KEY'] ?? ''),
            'VAPID_SUBJECT' => (string) ($_ENV['VAPID_SUBJECT'] ?? ''),
        ];
    }

    /** The window as `width,height`. A phone; bin/baseline.php narrows it to the baseline's 390 × 844. */
    protected static function windowSize(): string
    {
        return self::VIEWPORT_WIDTH . ',' . self::VIEWPORT_HEIGHT;
    }

    /** @return list<string> */
    private static function chromeArguments(): array
    {
        return [
            '--headless=new',
            '--window-size=' . static::windowSize(),
            // no classic scrollbar eating into the width, so clientWidth is the viewport's
            '--hide-scrollbars',
            '--disable-gpu',
            // the screen slide and the sheet animate otherwise, and a test would wait them out
            '--force-prefers-reduced-motion',
            // Only the test server resolves, so every third-party request (the Maps
            // iframe, Chrome's own Google traffic) fails at once. Otherwise one request
            // that hangs after it is sent blocks the load event. Chrome has no timeout for
            // that case, so the WebDriver navigate never returns and php-webdriver's 180 s
            // curl timeout errors the test. The lane is hermetic, and the fonts and icons
            // are self-hosted, so it still renders the real look.
            '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE ' . self::host(),
        ];
    }

    /**
     * Headless Chrome clamps its window to a minimum of 500px wide, so --window-size alone
     * does not give a phone: where the page reports another width, the device metrics are
     * overridden over CDP (chromedriver's goog/cdp endpoint, which Selenium forwards too).
     * The override belongs to the tab and outlives every navigation in it.
     */
    private static function ensureViewport(): void
    {
        if (self::script('return window.innerWidth;') === self::VIEWPORT_WIDTH) {
            return;
        }
        self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
    }

    /**
     * Sets the page's viewport over CDP, the only way below headless Chrome's 500px
     * window minimum. A test that narrows it puts it back to VIEWPORT_WIDTH × _HEIGHT,
     * since the browser is shared by the whole class.
     */
    protected static function overrideViewport(int $width, int $height): void
    {
        $driver = self::$browser->getWebDriver();
        if (!$driver instanceof RemoteWebDriver) {
            throw new \RuntimeException('Cannot reach CDP to set the viewport');
        }
        (new ChromeDevToolsDriver($driver))->execute('Emulation.setDeviceMetricsOverride', [
            'width' => $width,
            'height' => $height,
            'deviceScaleFactor' => 1,
            'mobile' => false,
        ]);
    }

    protected static function localClient(): Client
    {
        return Client::createChromeClient(self::chromeDriver(), [
            ...self::chromeArguments(),
            // ubuntu-24.04 runners restrict unprivileged user namespaces, so Chrome's sandbox
            // cannot start there; this browser only ever loads our own local test server
            '--no-sandbox',
            // a CI container's /dev/shm is small enough to crash a renderer
            '--disable-dev-shm-usage',
        ], [], self::baseUri());
    }

    protected static function remoteClient(): Client
    {
        $options = new ChromeOptions();
        $options->addArguments([
            ...self::chromeArguments(),
            // http://test:9080 is not a secure context, and without one register() throws:
            // every worker test would then fail, honestly, rather than skip
            '--unsafely-treat-insecure-origin-as-secure=' . self::baseUri(),
        ]);
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

        return Client::createSeleniumClient(self::env('PANTHER_SELENIUM_URL'), $capabilities, self::baseUri());
    }

    protected static function removeDatabase(): void
    {
        $file = dirname(__DIR__, 2) . '/' . self::DATABASE;
        foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
