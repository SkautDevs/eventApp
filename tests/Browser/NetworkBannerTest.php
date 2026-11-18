<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The one network banner above the tab bar (www/js/shell/netstate.js drawNetstate()): absent online,
 * and offline it says how old the visible screen's data is. The time is the visible
 * screen's own data-fetched-at, so switching screens changes it at once.
 *
 * The network is taken away twice over: the PHP server is stopped (CDP's offline emulation
 * does not reach the worker's fetches) and CDP's emulation turns navigator.onLine false,
 * which a stopped server alone never does.
 */
#[Group('browser')]
final class NetworkBannerTest extends BrowserTestCase
{
    /** What the banner should say for a screen fetched at $fetched, read at the page's clock. */
    private const string EXPECTED = <<<'JS'
        const two = n => (n < 10 ? '0' : '') + n;
        const at = new Date(arguments[0]);
        return 'Jsi offline. Data byla naposledy aktualizována v ' + two(at.getHours()) + ':' + two(at.getMinutes()) + ' (' + arguments[1] + ').';
        JS;

    protected function setUp(): void
    {
        parent::setUp();
        // a test before may have taken it away
        self::startServer();
    }

    private static function banner(): ?string
    {
        return self::script('const b = document.querySelector("[data-netstate]"); return b.hidden ? null : b.textContent;');
    }

    /** Pins the page's clock and the two screens' fetch times; the banner reads both. */
    private static function stamp(string $path, string $fetchedAt): void
    {
        self::script(<<<'JS'
            const section = document.querySelector('[data-screen="' + arguments[0] + '"]');
            section.setAttribute('data-fetched-at', arguments[1]);
            section.dispatchEvent(new CustomEvent('screen:freshness', {bubbles: true}));
            JS, [$path, $fetchedAt]);
    }

    public function testOfflineTheBannerSaysHowOldTheVisibleScreenIs(): void
    {
        self::visit('/korbo26/');
        self::waitForPrecache('korbo26');
        // the warm-up has put Informace into the document, so a tap shows it without a fetch
        self::waitFor('return document.querySelector(\'[data-screen="/korbo26/informace"]\') !== null;');
        self::assertSame(1, self::script('return document.querySelectorAll("[data-netstate]").length;'));
        self::assertNull(self::banner(), 'online and fresh: no banner');

        self::script("window.pgClock = function () { return Date.parse('2027-05-20T10:45:00+02:00'); };");
        self::stamp('/korbo26/', '2027-05-20T10:20:00+02:00');
        self::stamp('/korbo26/informace', '2027-05-20T08:30:00+02:00');
        self::assertNull(self::banner(), 'an old copy online is not stale');

        self::stopServer();
        $devTools = self::devTools();
        $devTools->execute('Network.enable');
        $devTools->execute('Network.emulateNetworkConditions', ['offline' => true, 'latency' => 0, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
        try {
            self::waitFor('return navigator.onLine === false && !document.querySelector("[data-netstate]").hidden;');
            self::assertSame(self::script(self::EXPECTED, ['2027-05-20T10:20:00+02:00', 'před 25 min']), self::banner());

            // a screen fetched earlier: the time changes with the switch, not a minute later
            self::tap('.tabbar a[href="/korbo26/informace"]');
            self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/informace"]\'); return s && !s.hidden;');
            self::assertSame(self::script(self::EXPECTED, ['2027-05-20T08:30:00+02:00', 'před 2 h']), self::banner());

            // the age ticks with the clock
            self::script("window.pgClock = function () { return Date.parse('2027-05-22T09:00:00+02:00'); }; document.dispatchEvent(new CustomEvent('pg:tick'));");
            self::assertSame(
                self::script('const two = n => (n < 10 ? "0" : "") + n; const at = new Date(arguments[0]); return "Jsi offline. Data byla naposledy aktualizována v " + two(at.getHours()) + ":" + two(at.getMinutes()) + " z " + at.getDate() + ". " + (at.getMonth() + 1) + ".";', ['2027-05-20T08:30:00+02:00']),
                self::banner(),
            );
        } finally {
            $devTools->execute('Network.emulateNetworkConditions', ['offline' => false, 'latency' => 0, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
            $devTools->execute('Network.disable');
        }

        // back online: gone
        self::waitFor('return navigator.onLine === true && document.querySelector("[data-netstate]").hidden;');
    }

    /** Takes the network away as far as the page can tell, without stopping the server. */
    private static function fakeOffline(): void
    {
        self::waitFor('return document.readyState === "complete";');
        self::script("Object.defineProperty(navigator, 'onLine', {get: () => false, configurable: true}); window.dispatchEvent(new Event('offline'));");
        self::waitFor('return !document.querySelector("[data-netstate]").hidden;');
    }

    /** The visible screen's view switcher stands on the banner: nothing of it is under it, and a tap reaches it. */
    private static function assertTheSwitcherClearsTheBanner(): void
    {
        $geometry = self::script(<<<'JS'
            const wrap = document.querySelector('main > [data-screen]:not([hidden]) .tabs-wrap').getBoundingClientRect();
            const banner = document.querySelector('[data-netstate]').getBoundingClientRect();
            const x = wrap.left + wrap.width / 4;
            const y = wrap.top + wrap.height / 2;
            const hit = document.elementFromPoint(x, y);
            return {
                gap: banner.top - wrap.bottom,
                reached: hit !== null && hit.closest('.tabs-wrap') !== null,
                published: getComputedStyle(document.documentElement).getPropertyValue('--netstate-height').trim(),
                height: Math.ceil(banner.height),
            };
            JS);
        self::assertGreaterThan(-1, $geometry['gap'], 'the switcher reaches under the banner');
        self::assertEqualsWithDelta(0, $geometry['gap'], 1, 'the switcher stands right on the banner');
        self::assertTrue($geometry['reached'], 'a tap on the switcher reaches it');
        self::assertSame($geometry['height'] . 'px', $geometry['published']);
    }

    /** M1: offline on Program the banner and the docked view pill share the foot of the screen. */
    public function testOfflineTheProgramViewSwitcherStandsOnTheBanner(): void
    {
        self::visit('/korbo26/programy');
        self::assertSame('0px', self::script('return getComputedStyle(document.documentElement).getPropertyValue("--netstate-height").trim();'), 'nothing published while it is hidden');
        self::fakeOffline();

        self::assertTheSwitcherClearsTheBanner();
        // the timeline's column stays one screenful: nothing scrolls the document
        self::assertLessThanOrEqual(1, self::script('return document.documentElement.scrollHeight - window.innerHeight;'));
        // the pill still works: a tap switches the view
        self::tap('[data-screen="/korbo26/programy"] .tabs-tab:not([aria-selected="true"])');
        self::waitFor('return document.querySelector(".pg").getAttribute("data-view") !== "timeline";');
    }

    /** M1: the same on a Místa with both views, whose pill is the Program screen's. */
    public function testOfflineTheMapViewSwitcherStandsOnTheBanner(): void
    {
        self::visit('/obrok19/mapa');
        self::fakeOffline();

        self::assertTheSwitcherClearsTheBanner();
    }

    /** M1: on a screen that scrolls, the last thing on it can be scrolled clear of the banner. */
    public function testTheLastLineOfAScrollingScreenClearsTheBanner(): void
    {
        self::overrideViewport(self::VIEWPORT_WIDTH, 480);
        try {
            // obrok19's Informace: the crisis line, the handbook and five links
            self::visit('/obrok19/informace');
            self::fakeOffline();
            self::assertGreaterThan(0, self::script('return document.documentElement.scrollHeight - window.innerHeight;'), 'Informace scrolls at this height');

            self::script('window.scrollTo(0, document.documentElement.scrollHeight);');
            $gap = self::script(<<<'JS'
                const section = document.querySelector('[data-screen="/obrok19/informace"]');
                const last = section.lastElementChild.getBoundingClientRect();
                return document.querySelector('[data-netstate]').getBoundingClientRect().top - last.bottom;
                JS);
            self::assertGreaterThanOrEqual(0, $gap, 'the last element stays under the banner');
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
    }

    /**
     * D5 and the live region: under a minute the banner says "právě teď", never "před 0 min";
     * the ticking age stays out of what a screen reader hears, and the banner itself is
     * hidden from it so nothing is read twice.
     */
    public function testTheBannerSaysJustNowAndItsLiveRegionDoesNotTick(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');
        self::script("window.pgClock = function () { return Date.parse('2027-05-20T10:20:40+02:00'); };");
        self::stamp('/korbo26/', '2027-05-20T10:20:00+02:00');
        self::fakeOffline();

        self::assertSame(self::script(self::EXPECTED, ['2027-05-20T10:20:00+02:00', 'právě teď']), self::banner());
        self::assertSame('true', self::script('return document.querySelector("[data-netstate]").getAttribute("aria-hidden");'));
        self::assertNull(self::script('return document.querySelector("[data-netstate]").getAttribute("role");'));
        $live = 'return document.querySelector("[data-netstate-live]").textContent;';
        $spoken = self::script($live);
        self::assertSame(
            self::script('const two = n => (n < 10 ? "0" : "") + n; const at = new Date(arguments[0]); return "Jsi offline. Data byla naposledy aktualizována v " + two(at.getHours()) + ":" + two(at.getMinutes()) + ".";', ['2027-05-20T10:20:00+02:00']),
            $spoken,
        );
        self::assertSame('status', self::script('return document.querySelector("[data-netstate-live]").getAttribute("role");'));

        // a minute on, the banner's age moves and the live region does not
        self::script("window.pgClock = function () { return Date.parse('2027-05-20T10:45:00+02:00'); }; document.dispatchEvent(new CustomEvent('pg:tick'));");
        self::assertSame(self::script(self::EXPECTED, ['2027-05-20T10:20:00+02:00', 'před 25 min']), self::banner());
        self::assertSame($spoken, self::script($live));

        // back online: both are empty
        self::script("Object.defineProperty(navigator, 'onLine', {get: () => true, configurable: true}); window.dispatchEvent(new Event('online'));");
        self::assertSame('', self::script($live));
        self::assertNull(self::banner());
        self::assertSame('0px', self::script('return getComputedStyle(document.documentElement).getPropertyValue("--netstate-height").trim();'));
    }

    /** Docked right above the tab bar, inside the column. */
    public function testTheBannerSitsOnTheTabBar(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');
        self::script("Object.defineProperty(navigator, 'onLine', {get: () => false, configurable: true}); window.dispatchEvent(new Event('offline'));");
        self::waitFor('return !document.querySelector("[data-netstate]").hidden;');

        $gap = self::script('return document.querySelector(".tabbar").getBoundingClientRect().top - document.querySelector("[data-netstate]").getBoundingClientRect().bottom;');
        self::assertEqualsWithDelta(0, $gap, 1);
        self::assertTrue(self::script('const b = document.querySelector("[data-netstate]").getBoundingClientRect(); return b.left >= 0 && b.right <= window.innerWidth && b.height > 0;'));
    }
}
