<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class WorkerRegistrationTest extends BrowserTestCase
{
    /** No tap on the notification button: the page alone registers the event's worker. */
    public function testEveryPageRegistersTheEventsWorkerAndThePrecacheListIsServed(): void
    {
        self::visit('/korbo26/');

        $scope = self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            navigator.serviceWorker.ready.then(registration => done(registration.scope), error => done('error: ' + error));
            JS);
        self::assertSame(self::baseUri() . '/korbo26/', $scope);

        // through bin/router.php: the built-in server alone would 404 a path with a dot
        $list = self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            fetch('/korbo26/precache.json').then(response => response.json()).then(done, error => done({error: String(error)}));
            JS);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string) ($list['version'] ?? ''));
        self::assertContains('/korbo26/offline', $list['documents'] ?? []);
    }

    /**
     * A login the worker never saw (posted while it was still installing) leaves pages in
     * the cache rendered for somebody else. The next page from the network says who the
     * cookie belongs to now, and the copies of everybody else go and come back refilled.
     */
    public function testAPageForAnotherIdentityIsReplacedByTheNextNetworkPage(): void
    {
        self::visit('/korbo26/');
        $cache = self::waitForPrecache('korbo26');
        self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.open(arguments[0]).then(cache => cache.put(location.origin + '/korbo26/novinky', new Response(
                '<!DOCTYPE html><meta name="push-identity" content="SOMEONE-ELSE"><p>stale</p>',
                {headers: {'Content-Type': 'text/html; charset=utf-8'}},
            ))).then(() => done(true), () => done(false));
            JS, [$cache]);

        self::visit('/korbo26/');

        self::waitForAsync(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.open(arguments[0])
                .then(cache => cache.match(location.origin + '/korbo26/novinky'))
                .then(hit => (hit ? hit.text() : ''))
                .then(html => done(html.includes('<meta name="push-identity" content="">') && !html.includes('SOMEONE-ELSE')), () => done(false));
            JS, [$cache]);
        self::assertTrue(true);
    }
}
