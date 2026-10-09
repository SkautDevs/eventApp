<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;

final class ServiceWorkerTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/www/sw.js');
    }

    public function testATapOpensTheMessagesUrlOnlyInsideTheWorkersScope(): void
    {
        $sw = $this->source();

        self::assertStringContainsString('notification.data', $sw);
        self::assertStringContainsString('url.href.startsWith(scope)', $sw);
    }

    public function testATapReusesAnOpenWindow(): void
    {
        $sw = $this->source();

        self::assertStringContainsString('clients.matchAll', $sw);
        self::assertStringContainsString('.navigate(', $sw);
    }

    public function testItInstallsActivatesAndServes(): void
    {
        $sw = $this->source();

        foreach (["addEventListener('install'", "addEventListener('activate'", "addEventListener('fetch'"] as $listener) {
            self::assertStringContainsString($listener, $sw);
        }
    }

    public function testThePrecacheListComesFromTheServer(): void
    {
        $sw = $this->source();

        self::assertStringContainsString("const PREFIX = 'eventapp-' + SLUG + '-';", $sw);
        self::assertStringContainsString("fetch(SCOPE + 'precache.json'", $sw);
        self::assertStringContainsString('fill(PREFIX + list.version, list)', $sw);
        self::assertStringContainsString('list.documents.map(url => storeBoth(cache, url))', $sw);
        self::assertStringContainsString('cache.addAll(list.assets)', $sw);
        // optional entries one by one, a failure ignored
        self::assertStringContainsString('list.optional.reduce(', $sw);
        // a fill that fails takes its half-filled cache with it (unless another fill completed it)
        self::assertStringContainsString(": caches.delete(name)))\n\t\t\t\t.then(() => { throw e; }));", $sw);
        // the stored list is the completion marker: written after every document and asset
        $fill = strpos($sw, 'function fill(name, list) {');
        self::assertNotFalse($fill);
        $all = strpos($sw, 'Promise.all([', $fill);
        $marker = strpos($sw, "cache.put(SCOPE + 'precache.json'", $fill);
        self::assertNotFalse($all);
        self::assertNotFalse($marker);
        self::assertGreaterThan($all, $marker);
        self::assertGreaterThan($marker, strpos($sw, 'list.optional.reduce(', $fill));
        // only a complete cache is ever adopted
        self::assertStringContainsString(".then(cache => cache.match(SCOPE + 'precache.json'))", $sw);
        self::assertStringContainsString('(cacheName !== null ? Promise.resolve(cacheName) : newestComplete())', $sw);
        // a cache that already holds its list is complete: fill() neither refetches nor deletes it
        self::assertStringContainsString("return caches.open(name).then(cache => cache.match(SCOPE + 'precache.json').then(complete => {", $sw);
        $guard = strpos($sw, 'if (complete) {', $fill);
        self::assertNotFalse($guard);
        self::assertLessThan($all, $guard);
        self::assertLessThan(strpos($sw, '.catch(e => cache.match(', $fill), $guard);
        // finding nothing complete deletes nothing
        self::assertStringContainsString("function adopt(name) {\n\tif (name === null) {\n", $sw);
    }

    public function testTheFragmentIsKeptUnderAKeyOfItsOwn(): void
    {
        $sw = $this->source();

        self::assertStringContainsString("if (request.headers.get('X-Screen') === '1') url.searchParams.set('x-screen', '1');", $sw);
        self::assertStringContainsString("new Request(url, {headers: {'X-Screen': '1'}})", $sw);
        self::assertStringContainsString('cache.put(cacheKey(request), response)', $sw);
    }

    public function testANewWorkerTakesOverWithoutAReload(): void
    {
        $sw = $this->source();

        self::assertStringContainsString('self.skipWaiting()', $sw);
        self::assertStringContainsString('self.clients.claim()', $sw);
        self::assertStringContainsString("type: 'sw-activated'", $sw);
        // a new list version under an unchanged sw.js: checked once, after a page from the network
        self::assertStringContainsString("fetchList('no-cache')", $sw);
        self::assertStringContainsString('let versionChecked = false;', $sw);
        self::assertStringContainsString('return fill(name, fresh).then(() => (superseded() ? null : adopt(name).then(adopted => (adopted ? announce() : null))));', $sw);
        self::assertStringContainsString('return Boolean(self.serviceWorker && self.registration.active !== self.serviceWorker);', $sw);
        self::assertStringContainsString('(storable(result.response) ? checkVersion() : null)', $sw);
        self::assertStringNotContainsString('.reload(', $sw);
    }

    public function testOnlyThisEventsOldCachesAreDropped(): void
    {
        $sw = $this->source();

        // PREFIX and an 8-hex version exactly: ab-26 never touches ab-26-27's caches
        self::assertStringContainsString("const MINE = new RegExp('^' + PREFIX + '[0-9a-f]{8}$');", $sw);
        self::assertStringContainsString('keys.filter(key => MINE.test(key))', $sw);
        self::assertStringNotContainsString('startsWith(PREFIX)', $sw);
        self::assertStringContainsString('keys.filter(key => MINE.test(key) && key !== cacheName).map(key => caches.delete(key))', $sw);
    }

    public function testAdminPushAndOtherOriginsAreLeftToTheBrowser(): void
    {
        $sw = $this->source();

        self::assertStringContainsString('if (url.origin !== self.location.origin) {', $sw);
        self::assertStringContainsString("url.pathname.startsWith(BASE + 'admin/') || url.pathname.startsWith(BASE + 'push/') || url.pathname === BASE + 'precache.json'", $sw);
        // an event file revalidates past a year of immutable HTTP caching
        self::assertStringContainsString("fetch(request, {cache: 'no-cache'})", $sw);
    }

    /** /fonts/ and /vendor/ follow "a changed file gets a new name", so a cached copy is always right. */
    public function testSelfHostedFontsAreCacheFirst(): void
    {
        $sw = $this->source();

        self::assertStringContainsString(
            "if ((url.searchParams.has('v') && /^\\/[a-z]+\\.(css|js)$/.test(url.pathname)) || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/vendor/')) {\n\t\tevent.respondWith(cacheFirst(event));",
            $sw,
        );
    }

    public function testAWriteEmptiesThePagesBeforeItsAnswerArrives(): void
    {
        $sw = $this->source();

        // purged once the server has answered, before the answer reaches the page: a POST
        // that fails offline leaves the offline copy alone
        self::assertStringContainsString('event.respondWith(fetch(request).then(response => purgeHtml().catch(() => null).then(() => response), () => offlineAnswer()));', $sw);
        self::assertStringNotContainsString('purgeHtml().catch(() => null).then(() => fetch(request))', $sw);
        // a subscribe or unsubscribe changes no rendered HTML, so it purges nothing
        self::assertStringContainsString("if (inScope && !url.pathname.startsWith(BASE + 'admin/') && !url.pathname.startsWith(BASE + 'push/'))", $sw);
        self::assertStringContainsString(".startsWith('text/html')", $sw);
    }

    public function testAPagePrefersTheNetworkAndFallsBackToTheOfflinePage(): void
    {
        $sw = $this->source();

        self::assertStringContainsString('const NETWORK_TIMEOUT = 3000;', $sw);
        self::assertStringContainsString('if (self.navigator.onLine === false) {', $sw);
        self::assertStringContainsString("match(SCOPE + 'offline')", $sw);
        self::assertStringContainsString("if (request.mode === 'navigate') {", $sw);
        // a 5xx never beats a saved copy; without one it passes through, not /offline
        self::assertStringContainsString('if (result.response.status >= 500) {', $sw);
        self::assertStringContainsString('match(key).then(hit => finish(hit || result.response), () => finish(result.response));', $sw);
    }

    public function testAPushTellsTheOpenAppAboutTheNews(): void
    {
        $sw = $this->source();

        self::assertStringContainsString("type: 'news-updated'", $sw);
        self::assertStringContainsString("programme: typeof data.programme === 'number' ? data.programme : null", $sw);
    }

    /** Review Focus 5: a timestamp is not a change, or every navigation would fetch twice. */
    public function testAChangedFragmentIsReportedAndTheRevalidationGoesToTheNetwork(): void
    {
        $sw = $this->source();

        self::assertStringContainsString("type: 'screen-updated'", $sw);
        self::assertStringContainsString('/ data-fetched-at="[^"]*"| data-stale="1"/g', $sw);
        self::assertStringContainsString("request.cache === 'no-cache' ? fragmentFromNetwork(event) : fragmentFromCache(event)", $sw);
    }

    public function testOnlyWholeSameOriginAnswersAreKeptAndAPurgedCacheRefills(): void
    {
        $sw = $this->source();

        self::assertStringContainsString("return response.ok && response.type === 'basic' && !response.redirected;", $sw);
        self::assertStringContainsString('Promise.all([putHtml(generation, key, response.clone()), response.clone().text()])', $sw);
        // a page for another identity in the cache means a login the worker never saw
        self::assertStringContainsString('.then(([, html]) => reconcile(key, html))', $sw);
        self::assertStringContainsString('.then(refill)', $sw);
        self::assertStringContainsString('identities.some(other => other !== null && other !== who) ? purgeHtml() : null', $sw);
        self::assertStringContainsString('function refill() {', $sw);
        // a write whose request started before a purge carries the old identity: dropped
        self::assertStringContainsString('purges += 1;', $sw);
        self::assertStringContainsString('cache && unpurged(generation) ? cache.put(key, response) : undefined', $sw);
        self::assertStringContainsString('return unpurged(generation) ? cache.put(cacheKey(request), response) : null;', $sw);
        self::assertSame(4, substr_count($sw, 'const generation = purges;'), 'storeBoth, page, and both fragment strategies');
    }

    public function testAWriteWithoutNetworkGetsTheOfflinePageAsA503(): void
    {
        $sw = $this->source();
        self::assertStringContainsString('event.respondWith(fetch(request).then(response => purgeHtml().catch(() => null).then(() => response), () => offlineAnswer()));', $sw);
        self::assertStringContainsString("new Response(OFFLINE_WRITE, {status: 503, headers: {'Content-Type': 'text/html; charset=utf-8'}})", $sw);
        self::assertStringContainsString('Přihlášení potřebuje signál.', $sw);
        // always the inline page: the cached /offline says "this page is not saved yet", which is not what happened
        self::assertStringNotContainsString("match(SCOPE + 'offline').then(hit => (hit ? hit.text()", $sw);
    }

    public function testOnlyACompleteCacheThatStillExistsIsAdopted(): void
    {
        $sw = $this->source();
        self::assertStringContainsString('return caches.has(name).then(exists => (exists', $sw);
        self::assertStringContainsString('return isComplete(name).then(complete => {', $sw);
        self::assertStringContainsString('if (self.registration.installing || self.registration.waiting) {', $sw);
        self::assertStringContainsString('adopt(name).then(adopted => (adopted ? announce() : null))', $sw);
    }

    public function testAPurgeEmptiesEveryCacheOfTheEvent(): void
    {
        $sw = $this->source();
        self::assertStringContainsString('return caches.keys().then(keys => Promise.all(keys.filter(key => MINE.test(key)).map(key => caches.open(key)', $sw);
    }

    public function testARevalidationAnsweredFromTheCacheSaysSo(): void
    {
        self::assertStringContainsString("headers.set('X-From-Cache', '1');", $this->source());
        self::assertStringContainsString("response.headers.get('X-From-Cache') !== '1'", (string) file_get_contents(dirname(__DIR__, 2) . '/www/app.js'));
    }

    /** Review M1: a failed fill spares a cache another fill completed, and activate forgets a name it could not adopt. */
    public function testAFailedFillOrAdoptionNeverLeavesTheWorkerOnAnEmptyCache(): void
    {
        $sw = $this->source();
        self::assertStringContainsString(".catch(e => cache.match(SCOPE + 'precache.json')\n\t\t\t\t.then(marker => (marker ? null : caches.delete(name)))", $sw);
        self::assertStringNotContainsString('.catch(e => caches.delete(name).then(() => { throw e; }))', $sw);
        self::assertStringContainsString(".then(name => adopt(name))\n\t\t.then(adopted => {\n\t\t\tif (!adopted) {", $sw);
        self::assertStringContainsString("\t\t\t\tcacheName = null;\n", $sw);
    }
}
