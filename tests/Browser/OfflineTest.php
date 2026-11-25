<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The meadow with no signal. The network is taken away by stopping the PHP server: CDP's
 * offline emulation does not reach the service worker's own fetches, so it could prove
 * nothing here.
 */
#[Group('browser')]
final class OfflineTest extends BrowserTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // the previous test took it away
        self::startServer();
    }

    public function testEverySeenScreenOpensWithoutTheServer(): void
    {
        self::visit('/korbo26/');
        self::waitForPrecache('korbo26');

        self::stopServer();
        self::$browser->reload();
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/"]\'); return s && !s.hidden;');

        foreach (['/korbo26/programy' => '.tl-card', '/korbo26/informace' => 'p'] as $path => $content) {
            self::tap('.tabbar a[href="' . $path . '"]');
            self::waitFor(
                'const s = document.querySelector(\'[data-screen="\' + arguments[0] + \'"]\'); return s && !s.hidden && s.querySelector(arguments[1]) !== null;',
                [$path, $content],
            );
        }
        self::tap('.tabbar a[href="/korbo26/profil"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/profil"]\'); return s && !s.hidden;');

        // a page the worker never kept
        self::visit('/korbo26/neni');
        self::waitFor('return document.body.textContent.includes(arguments[0]);', ['Jsi offline a tahle stránka ještě není uložená.']);
    }

    /**
     * Review Focus 3: a view address is an alias of its screen, kept under the screen's own
     * address, so with no server it opens from the stored Program screen, not /offline.
     */
    public function testAViewAddressOpensFromTheStoredScreen(): void
    {
        self::visit('/korbo26/');
        self::waitForPrecache('korbo26');

        self::stopServer();
        try {
            self::visit('/korbo26/programy/moje');
            self::waitFor('return document.querySelector(\'[data-screen="/korbo26/programy"]\') !== null;');
            self::assertNull(self::script('return document.querySelector(".offline");'));
            // the stored copy is the screen's own, painted on Harmonogram: the view comes from the address
            self::waitFor('const root = document.querySelector(\'[data-pg-root][data-pg-ready="1"]\'); return root !== null && root.dataset.view === "list";');
            self::assertSame('true', self::script('return document.querySelector(\'[data-pg-view="list"]\').getAttribute("aria-selected");'));
        } finally {
            self::startServer();
        }
    }

    /**
     * A day chip's address (`?den=`) is answered offline from the screen's stored copy, which
     * the server rendered on another day: the script puts the address's day on show.
     */
    public function testADayAddressOpensItsDayFromTheStoredScreen(): void
    {
        self::visit('/korbo26/');
        self::waitForPrecache('korbo26');

        self::stopServer();
        try {
            self::visit('/korbo26/programy?den=20260918');
            self::waitFor('const root = document.querySelector(\'[data-pg-root][data-pg-ready="1"]\'); return root !== null;');
            self::assertNull(self::script('return document.querySelector(".offline");'));
            self::assertSame(['page-20260918'], self::script('return Array.from(document.querySelectorAll(".tl-page.is-active"), page => page.dataset.key);'));
            self::assertSame('true', self::script('return document.querySelector(\'.day-chip[data-key="page-20260918"]\').getAttribute("aria-current");'));
        } finally {
            self::startServer();
        }
    }

    /**
     * The other way round: a visit to Moje stores Moje's markup under the screen's key, and
     * the screen's own address, opened from that copy, is still Harmonogram.
     */
    public function testTheScreenAddressOpensOnItsOwnViewFromAnAliasCopy(): void
    {
        self::visit('/korbo26/');
        $cache = self::waitForPrecache('korbo26');
        self::visit('/korbo26/programy/moje');
        self::waitForCachedPage($cache, '/korbo26/programy', 'data-view="list"');

        self::stopServer();
        try {
            self::visit('/korbo26/programy');
            self::waitFor('const root = document.querySelector(\'[data-pg-root][data-pg-ready="1"]\'); return root !== null && root.dataset.view === "timeline";');
            self::assertSame('true', self::script('return document.querySelector(\'[data-pg-view="timeline"]\').getAttribute("aria-selected");'));
            self::assertSame('false', self::script('return document.querySelector(\'[data-pg-view="list"]\').getAttribute("aria-selected");'));
        } finally {
            self::startServer();
        }
    }

    /**
     * An online visit to a view address stores the page under its screen's key only: the
     * worker's caches gain no entry of the view's own address.
     */
    public function testAViewVisitIsStoredUnderTheScreenKeyAlone(): void
    {
        self::visit('/korbo26/');
        $cache = self::waitForPrecache('korbo26');
        self::visit('/korbo26/programy/moje');
        self::waitForCachedPage($cache, '/korbo26/programy', 'data-view="list"');

        $paths = self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.keys()
                .then(names => Promise.all(names.map(name => caches.open(name).then(cache => cache.keys()))))
                .then(lists => done([].concat(...lists).map(request => new URL(request.url).pathname)), () => done(null));
            JS);
        self::assertIsArray($paths);
        self::assertContains('/korbo26/programy', $paths);
        self::assertNotContains('/korbo26/programy/moje', $paths);
    }

    /** Review Focus 4: the crisis line is the one thing that must be there with no signal. */
    public function testTheCrisisContactIsThereWithoutTheServer(): void
    {
        self::visit('/obrok27/informace');
        self::waitForPrecache('obrok27');

        self::stopServer();
        self::$browser->reload();
        self::waitFor('const s = document.querySelector(\'[data-screen="/obrok27/informace"]\'); return s && !s.hidden;');
        self::waitFor('return !!document.querySelector(".info-contact a.emergency-call[href=\'tel:+420704444015\']");');
    }

    /**
     * Google's map is a blank box with no signal, so Mapa opens on the plan, from the cache,
     * and the address says so: the bare /mapa is replaced by /mapa/plan, with no new step.
     */
    public function testTheMapOpensOnThePlanWithoutTheServer(): void
    {
        self::visit('/obrok19/mapa');
        self::script('sessionStorage.clear();');
        self::waitForPrecache('obrok19');
        $length = self::script('return history.length;');

        self::stopServer();
        self::networkOffline(true);
        try {
            self::$browser->reload();
            self::waitFor('const s = document.querySelector(\'[data-screen="/obrok19/mapa"]\'); return s && !s.hidden;');
            self::assertFalse(self::script('return navigator.onLine;'));
            self::waitFor('const root = document.querySelector("[data-map-root]"); return root !== null && root.dataset.mapReady === "1" && root.dataset.view === "plan";');
            self::waitFor('const img = document.querySelector("[data-plan-img]"); return img.complete && img.naturalWidth > 0;');
            self::assertSame('/obrok19/mapa/plan', self::script('return location.pathname;'));
            self::assertSame($length, self::script('return history.length;'), 'the replace added no step');
        } finally {
            self::networkOffline(false);
        }
    }

    /** A Místa already wired, shown again on its bare address with no signal, moves to the plan too. */
    public function testTheMapShownAgainWithoutSignalMovesToThePlan(): void
    {
        self::visit('/obrok19/mapa');
        self::waitFor('const root = document.querySelector("[data-map-root]"); return root !== null && root.dataset.mapReady === "1" && root.dataset.view === "google";');
        self::tap('.tabbar a[href="/obrok19/informace"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/obrok19/informace"]\'); return s && !s.hidden;');

        self::networkOffline(true);
        try {
            self::tap('.tabbar a[href="/obrok19/mapa"]');
            self::waitFor('const s = document.querySelector(\'[data-screen="/obrok19/mapa"]\'); return s && !s.hidden'
                . ' && location.pathname === "/obrok19/mapa/plan" && s.querySelector("[data-map-root]").dataset.view === "plan";');
            self::assertSame('true', self::script('return document.querySelector(\'[role="tab"][data-map-view="plan"]\').getAttribute("aria-selected");'));
        } finally {
            self::networkOffline(false);
        }
    }

    /**
     * The worker keeps /mapa/plan's markup under /mapa, so a copy painted on the plan can
     * answer the map's address: the view comes from the address, not the copy's data-view.
     */
    public function testTheMapAddressOpensOnTheMapFromAPlanCopy(): void
    {
        self::visit('/obrok19/mapa');
        $cache = self::waitForPrecache('obrok19');
        self::visit('/obrok19/mapa/plan');
        self::waitForCachedPage($cache, '/obrok19/mapa', 'data-view="plan"');

        self::stopServer();
        try {
            // a stopped server leaves navigator.onLine true: Google's view is still the one asked for
            self::visit('/obrok19/mapa');
            self::waitFor('const root = document.querySelector("[data-map-root]"); return root !== null && root.dataset.mapReady === "1";');
            self::assertSame('google', self::script('return document.querySelector("[data-map-root]").dataset.view;'));
            self::assertSame('true', self::script('return document.querySelector(\'[role="tab"][data-map-view="google"]\').getAttribute("aria-selected");'));
            self::assertSame('/obrok19/mapa', self::script('return location.pathname;'));
        } finally {
            self::startServer();
        }
    }

    /** Review Focus 1: the purge on login must not leave the reader with nothing offline. */
    public function testALoginIsCarriedIntoTheOfflineCopy(): void
    {
        // logged out first, whatever ran before: this is about the login's purge
        self::visit('/korbo26/');
        self::$browser->manage()->deleteAllCookies();
        self::visit('/korbo26/profil');
        $cache = self::waitForPrecache('korbo26');
        self::pair();

        // the Program page the worker refilled after the purge is the logged-in one
        self::waitForCachedPage($cache, '/korbo26/programy', 'is-registered');

        self::stopServer();
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(".tl-card.is-registered") !== null;');
        self::assertSame('JN', self::script('return document.querySelector(".tab-avatar").textContent;'));
    }

    /**
     * A login tapped with no signal goes back where the reader was: the worker answers the
     * scripted POST with its referrer plus #offline as `{"next"}` (a plain form's gets a 303
     * there), shell/write.js reloads that address in place of the entry, the page comes from
     * the cache, and the shell says what happened in a toast and takes the hash away again. Logged out first,
     * whatever ran before: the start is only there for a reader who is not logged in.
     */
    public function testALoginWithoutTheServerGoesBackWithTheOfflineToast(): void
    {
        self::visit('/korbo26/');
        self::$browser->manage()->deleteAllCookies();
        self::visit('/korbo26/profil');
        $cache = self::waitForPrecache('korbo26');
        $start = '[data-screen="/korbo26/profil"] form[action="/korbo26/profil/propojit"] button[type="submit"]';
        self::waitFor('return document.querySelector(arguments[0]) !== null;', [$start]);
        // A browser left logged in by an earlier test holds pages of that identity: this
        // visit makes the worker purge them and refill them logged out, in the background.
        // The landing the tap gets is answered from these two copies, so the server stays up
        // until both are back, rendered logged out.
        foreach (['/korbo26/profil', '/korbo26/offline'] as $path) {
            self::waitForCachedPage($cache, $path, '<meta name="push-identity" content="">');
        }
        self::script('window.__before = 1;');
        $n = self::script('return history.length;');
        self::stopServer();

        self::tap($start);

        self::waitFor('const t = document.querySelector("[data-toast]"); return window.__before === undefined && t !== null && t.textContent.includes(arguments[0]);', ['Akci není možné provést, protože jsi offline.']);
        self::assertStringContainsString('Zkus to znovu až najdeš místo se signálem.', (string) self::script('return document.querySelector("[data-toast]").textContent;'));
        self::assertTrue(self::script('const t = document.querySelector("[data-toast]"); return t.closest("[data-screen]") === document.querySelector(\'[data-screen="/korbo26/profil"]\') && t.getClientRects().length > 0;'), 'on the visible Profil');
        self::assertStringEndsWith('/korbo26/profil', (string) self::script('return location.href;'), 'no #offline left behind');
        // the landing replaced the entry the write was sent from
        self::assertSame($n, self::script('return history.length;'));
        self::assertSame('/korbo26/profil', self::script('return location.pathname;'));
        self::assertStringContainsString('Nejsi přihlášen', (string) self::script('return document.querySelector(\'[data-screen="/korbo26/profil"]\').textContent;'));

        // the close button takes it away
        self::tap('[data-toast-close]');
        self::assertNull(self::script('return document.querySelector("[data-toast]");'));
    }

    /**
     * A revalidation the worker could only answer from its cache confirms nothing: the
     * screen stays due, so the next time it is shown it asks again — rather than counting
     * as fresh for five minutes after the signal is back.
     */
    public function testARevalidationAnsweredFromTheCacheLeavesTheScreenDue(): void
    {
        self::visit('/korbo26/');
        self::waitForPrecache('korbo26');
        self::tap('.tabbar a[href="/korbo26/informace"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/informace"]\'); return s && !s.hidden;');
        self::stopServer();

        // counts the loader's revalidations (cache: 'no-cache') and their answers
        self::script(<<<'JS'
            window.__checks = [];
            const original = window.fetch;
            window.fetch = function (input, init) {
                const promise = original.apply(this, arguments);
                if (init && init.cache === 'no-cache') {
                    const check = {done: false, fromCache: null};
                    window.__checks.push(check);
                    promise.then(response => { check.fromCache = response.headers.get('X-From-Cache'); check.done = true; }, () => { check.fromCache = 'error'; check.done = true; });
                }
                return promise;
            };
            navigator.serviceWorker.dispatchEvent(new MessageEvent('message', {data: {type: 'screen-updated', path: '/korbo26/informace'}}));
            JS);
        self::waitFor('return window.__checks.length === 1 && window.__checks[0].done;');
        self::assertSame('1', self::script('return window.__checks[0].fromCache;'), 'answered from the cache, and marked so');

        self::tap('.tabbar a[href="/korbo26/profil"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/profil"]\'); return s && !s.hidden;');
        self::tap('.tabbar a[href="/korbo26/informace"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/informace"]\'); return s && !s.hidden;');
        // shown again: still due, so asked again
        self::waitFor('return window.__checks.filter(check => check.done).length >= 2;');
    }

    /** Wait until the worker's cache $cache holds the page $path, and its HTML contains $needle. */
    /** A stopped server leaves navigator.onLine true; a phone with no signal does not. */
    private static function networkOffline(bool $offline): void
    {
        $devTools = self::devTools();
        if ($offline) {
            $devTools->execute('Network.enable');
        }
        $devTools->execute('Network.emulateNetworkConditions', ['offline' => $offline, 'latency' => 0, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
        if (!$offline) {
            $devTools->execute('Network.disable');
        }
    }

    private static function waitForCachedPage(string $cache, string $path, string $needle): void
    {
        self::waitForAsync(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.open(arguments[0])
                .then(cache => cache.match(arguments[1], {ignoreVary: true}))
                .then(response => (response ? response.text() : ''))
                .then(html => done(html.includes(arguments[2])), () => done(false));
            JS, [$cache, $path, $needle]);
    }
}
