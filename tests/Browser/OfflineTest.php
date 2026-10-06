<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
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

        foreach (['/korbo26/programy' => '.tl-card', '/korbo26/novinky' => 'p'] as $path => $content) {
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

    /** Review Focus 1: the purge on login must not leave the reader with nothing offline. */
    public function testALoginIsCarriedIntoTheOfflineCopy(): void
    {
        self::visit('/korbo26/profil');
        $cache = self::waitForPrecache('korbo26');
        self::$browser->findElement(WebDriverBy::cssSelector('[data-screen="/korbo26/profil"] input[name="tieCode"]'))->sendKeys('KORBO1');
        self::tap('[data-screen="/korbo26/profil"] button[type="submit"]');
        self::waitFor('const who = document.querySelector(".appbar-who"); return who !== null && who.textContent === "TIE KORBO1";');

        // the Program page the worker refilled after the purge is the logged-in one
        self::waitForAsync(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.open(arguments[0])
                .then(cache => cache.match('/korbo26/programy', {ignoreVary: true}))
                .then(response => (response ? response.text() : ''))
                .then(html => done(html.includes('is-registered')), () => done(false));
            JS, [$cache]);

        self::stopServer();
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(".tl-card.is-registered") !== null;');
        self::assertSame('TIE KORBO1', self::script('return document.querySelector(".appbar-who").textContent;'));
    }

    /**
     * C-I3: a login tapped with no signal shows the worker's own offline page as a 503, not
     * the browser's error page. Logged out first, whatever ran before: the form is only
     * there for a reader who is not logged in.
     */
    public function testALoginWithoutTheServerGetsTheOfflinePage(): void
    {
        self::visit('/korbo26/');
        self::$browser->manage()->deleteAllCookies();
        self::visit('/korbo26/profil');
        self::waitForPrecache('korbo26');
        self::waitFor('return document.querySelector(\'[data-screen="/korbo26/profil"] input[name="tieCode"]\') !== null;');
        self::stopServer();

        self::$browser->findElement(WebDriverBy::cssSelector('[data-screen="/korbo26/profil"] input[name="tieCode"]'))->sendKeys('KORBO1');
        self::tap('[data-screen="/korbo26/profil"] button[type="submit"]');

        self::waitFor('return document.body && document.body.textContent.includes("Přihlášení i odhlášení potřebuje signál.");');
        self::assertSame(503, self::script('return performance.getEntriesByType("navigation")[0].responseStatus;'));
        self::assertStringStartsWith('http', (string) self::script('return location.href;'), 'not chrome-error://');
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
        self::tap('.tabbar a[href="/korbo26/novinky"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/novinky"]\'); return s && !s.hidden;');
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
            navigator.serviceWorker.dispatchEvent(new MessageEvent('message', {data: {type: 'screen-updated', path: '/korbo26/novinky'}}));
            JS);
        self::waitFor('return window.__checks.length === 1 && window.__checks[0].done;');
        self::assertSame('1', self::script('return window.__checks[0].fromCache;'), 'answered from the cache, and marked so');

        self::tap('.appbar-profile');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/profil"]\'); return s && !s.hidden;');
        self::tap('.tabbar a[href="/korbo26/novinky"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/novinky"]\'); return s && !s.hidden;');
        // shown again: still due, so asked again
        self::waitFor('return window.__checks.filter(check => check.done).length >= 2;');
    }
}
