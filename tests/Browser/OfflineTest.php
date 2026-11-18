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
        self::tap('.appbar-profile');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/profil"]\'); return s && !s.hidden;');

        // a page the worker never kept
        self::visit('/korbo26/neni');
        self::waitFor('return document.body.textContent.includes(arguments[0]);', ['Jsi offline a tahle stránka ještě není uložená.']);
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

    /** Google's map is a blank box with no signal, so Mapa opens on the plan, from the cache. */
    public function testTheMapOpensOnThePlanWithoutTheServer(): void
    {
        self::visit('/obrok19/mapa');
        self::script('sessionStorage.clear();');
        self::waitForPrecache('obrok19');

        self::stopServer();
        // a stopped server leaves navigator.onLine true; a phone with no signal does not
        $devTools = self::devTools();
        $devTools->execute('Network.enable');
        $devTools->execute('Network.emulateNetworkConditions', ['offline' => true, 'latency' => 0, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
        try {
            self::$browser->reload();
            self::waitFor('const s = document.querySelector(\'[data-screen="/obrok19/mapa"]\'); return s && !s.hidden;');
            self::assertFalse(self::script('return navigator.onLine;'));
            self::waitFor('const root = document.querySelector("[data-map-root]"); return root !== null && root.dataset.mapReady === "1" && root.dataset.view === "plan";');
            self::waitFor('const img = document.querySelector("[data-plan-img]"); return img.complete && img.naturalWidth > 0;');
        } finally {
            $devTools->execute('Network.emulateNetworkConditions', ['offline' => false, 'latency' => 0, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
            $devTools->execute('Network.disable');
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
        self::assertSame('JN', self::script('return document.querySelector(".appbar-avatar").textContent;'));
    }

    /**
     * A login tapped with no signal goes back where the reader was: the worker answers the
     * POST with a 303 to its referrer plus #offline, the page comes from the cache, and the
     * shell says what happened in a toast and takes the hash away again. Logged out first,
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
        // The 303 the tap gets is answered from these two copies, so the server stays up
        // until both are back, rendered logged out.
        foreach (['/korbo26/profil', '/korbo26/offline'] as $path) {
            self::waitForCachedPage($cache, $path, '<meta name="push-identity" content="">');
        }
        self::script('window.__before = 1;');
        self::stopServer();

        self::tap($start);

        self::waitFor('const t = document.querySelector("[data-toast]"); return window.__before === undefined && t !== null && t.textContent.includes(arguments[0]);', ['Akci není možné provést, protože jsi offline.']);
        self::assertStringContainsString('Zkus to znovu až najdeš místo se signálem.', (string) self::script('return document.querySelector("[data-toast]").textContent;'));
        self::assertTrue(self::script('const t = document.querySelector("[data-toast]"); return t.closest("[data-screen]") === document.querySelector(\'[data-screen="/korbo26/profil"]\') && t.getClientRects().length > 0;'), 'on the visible Profil');
        self::assertStringEndsWith('/korbo26/profil', (string) self::script('return location.href;'), 'no #offline left behind');
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

        self::tap('.appbar-profile');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/profil"]\'); return s && !s.hidden;');
        self::tap('.tabbar a[href="/korbo26/informace"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/informace"]\'); return s && !s.hidden;');
        // shown again: still due, so asked again
        self::waitFor('return window.__checks.filter(check => check.done).length >= 2;');
    }

    /** Wait until the worker's cache $cache holds the page $path, and its HTML contains $needle. */
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
