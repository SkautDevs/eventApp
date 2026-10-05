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
}
