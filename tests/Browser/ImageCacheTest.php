<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The worker's photo cache, on korbo26's demo photos (www/events/korbo26/demo/): a photo
 * the reader has seen is kept in `eventapp-korbo26-img`, a cache of its own, cache-first;
 * past IMAGE_CAP (200) entries the oldest goes; and a write's purge leaves it alone. The cap
 * is reached by seeding the cache from the page, not by a smaller cap injected for the test:
 * `window.pgClock` and `window.appWarm` stay the only test seams.
 */
#[Group('browser')]
final class ImageCacheTest extends BrowserTestCase
{
    private const string CACHE = 'eventapp-korbo26-img';

    /** Volejbal's first photo (programme 1). */
    private const string PHOTO = '/events/korbo26/demo/volejbal-1.webp';

    /** The Program screen, controlled by the worker, its precache complete. */
    private static function openControlledProgramScreen(): void
    {
        self::visit('/korbo26/programy');
        self::waitForPrecache('korbo26');
        // the first page was not controlled yet; this one goes through the worker
        self::$browser->reload();
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null && navigator.serviceWorker.controller !== null;');
    }

    private static function openVolejbal(): void
    {
        self::script('document.querySelector(\'[data-screen="/korbo26/programy"] .tl-card[data-key="1"]\').click();');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="1"]\') !== null;');
    }

    /** Waits until the image cache holds Volejbal's first photo. */
    private static function waitForThePhoto(): void
    {
        self::waitForAsync(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.open(arguments[0])
                .then(cache => cache.match(location.origin + arguments[1]))
                .then(hit => done(Boolean(hit)), () => done(false));
            JS, [self::CACHE, self::PHOTO]);
    }

    /** @return list<string> the image cache's keys, oldest first */
    private static function keys(): array
    {
        $keys = self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.open(arguments[0])
                .then(cache => cache.keys())
                .then(requests => done(requests.map(request => request.url)), error => done('error: ' + error));
            JS, [self::CACHE]);
        self::assertIsArray($keys);

        return array_values(array_map('strval', $keys));
    }

    /** @param list<string> $keys */
    private static function holdsThePhoto(array $keys): bool
    {
        return array_filter($keys, static fn(string $key): bool => str_ends_with($key, self::PHOTO)) !== [];
    }

    public function testASeenPhotoIsKeptAndTheOldestGoesPastTheCap(): void
    {
        self::openControlledProgramScreen();
        // a full cache, written in order, so seed-0 is the oldest entry
        self::assertTrue(self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            const name = arguments[0];
            caches.delete(name)
                .then(() => caches.open(name))
                .then(cache => Array.from({length: 200}, (_, n) => n).reduce(
                    (written, n) => written.then(() => cache.put(location.origin + '/events/korbo26/demo/seed-' + n + '.webp', new Response('x'))),
                    Promise.resolve(),
                ))
                .then(() => done(true), () => done(false));
            JS, [self::CACHE]));

        self::openVolejbal();
        self::waitForThePhoto();

        // The worker puts the photo first and trims afterwards, so the cap is waited for, never
        // checked once. Volejbal's second photo may load too (it sits one frame to the right),
        // so the state waited for is the consistent one: back at the cap, the n oldest seeds
        // gone for the n photos kept, and the next seed still there.
        $state = self::waitForAsync(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.open(arguments[0]).then(cache => cache.keys()).then(requests => {
                const urls = requests.map(request => request.url);
                const seed = n => urls.some(url => url.endsWith('/seed-' + n + '.webp'));
                const photos = urls.filter(url => !/\/seed-\d+\.webp$/.test(url)).length;
                const gone = Array.from({length: 200}, (_, n) => n).filter(n => !seed(n));
                const settled = urls.length === 200 && !seed(0) && gone.length === photos && gone.every((n, i) => n === i);
                done(settled ? {length: urls.length, photos, gone, kept: seed(photos)} : false);
            }, () => done(false));
            JS, [self::CACHE]);

        self::assertIsArray($state);
        self::assertSame(200, $state['length']);
        self::assertGreaterThanOrEqual(1, $state['photos']);
        self::assertSame(range(0, $state['photos'] - 1), $state['gone'], 'the oldest went, one per photo kept');
        self::assertTrue($state['kept'], 'the next oldest stayed');
        self::assertTrue(self::holdsThePhoto(self::keys()));
    }

    /** A write purges every stored page and fragment; the photos are no page and stay. */
    public function testAWriteLeavesThePhotosAlone(): void
    {
        self::openControlledProgramScreen();
        // an empty cache, far from the cap: the thumbnails pairing lands among would otherwise
        // push the oldest entries out, which is the cap at work, not the purge
        self::assertTrue(self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.delete(arguments[0]).then(() => done(true), () => done(false));
            JS, [self::CACHE]));
        self::openVolejbal();
        self::waitForThePhoto();
        $before = self::keys();

        // a POST the worker sees, and purges on
        self::pair();

        $after = self::keys();
        self::assertTrue(self::holdsThePhoto($after), 'the photo is still kept');
        self::assertSame([], array_values(array_diff($before, $after)), 'no photo was deleted');
    }
}
