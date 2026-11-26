<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * A programme's photos in its sheet, on korbo26's demo photos: the gallery loads only once
 * its sheet opens (each photo `loading="lazy"` inside a body that is `display: none` while
 * closed), a loaded photo is marked `is-loaded`, the dots follow the strip's scroll, and a
 * revalidation morph keeps a loaded photo loaded (`data-morph-keep="class"`). Přehled's and
 * Moje's rows of a programme with photos carry the first one's thumbnail beside the name,
 * which leaves the time and the name where every other row has them, keeps clear of the
 * `probíhá` chip, and stays loaded through a morph too.
 */
#[Group('browser')]
final class ProgramPhotosTest extends BrowserTestCase
{
    /** Volejbal: two photos. */
    private const string VOLEJBAL = '1';

    private const string PHOTO = '.sheet-body[data-pg-detail="1"] img[data-photo]';

    /** Vzduchovky: three photos, so a thumbnail on its row. */
    private const string VZDUCHOVKY = '13';

    /** A programme without photos. */
    private const string WITHOUT_PHOTOS = '2';

    private const string OVERVIEW = '[data-screen="/korbo26/programy"] #pg-view-overview';

    private const string THUMB = self::OVERVIEW . ' .pl-item[data-key="13"] img.pl-thumb';

    private static function openProgramScreen(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
    }

    private static function openVolejbal(): void
    {
        self::script('document.querySelector(\'[data-screen="/korbo26/programy"] .tl-card[data-key="' . self::VOLEJBAL . '"]\').click();');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="' . self::VOLEJBAL . '"]\') !== null;');
    }

    private static function openOverview(): void
    {
        self::visit('/korbo26/programy/prehled');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"][data-view="overview"]\') !== null;');
    }

    /** Scrolls the thumbnail into view (it is lazy) and waits until gallery.js has marked it loaded. */
    private static function loadThumb(string $selector): void
    {
        self::script('document.querySelector(arguments[0]).scrollIntoView({block: "center"});', [$selector]);
        self::waitFor('const img = document.querySelector(arguments[0]); return img !== null && img.classList.contains("is-loaded");', [$selector]);
    }

    private static function waitForFirstPhoto(): void
    {
        self::waitFor('const img = document.querySelector(arguments[0]); return img !== null && img.classList.contains("is-loaded");', [self::PHOTO]);
    }

    /** Drops the session cookie and the stored pages, as ProgramDialogTest::logOut() does. */
    private static function logOut(): void
    {
        self::visit('/korbo26/');
        self::$browser->getWebDriver()->manage()->deleteAllCookies();
        self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.keys().then(names => Promise.all(names.map(name => caches.open(name).then(cache => cache.keys().then(requests => Promise.all(requests.map(request => cache.match(request).then(response => {
                if (response && (response.headers.get('Content-Type') || '').includes('text/html')) {
                    return cache.delete(request);
                }
                return false;
            })))))))).then(() => done(true), () => done(false));
            JS);
    }

    public function testTheGalleryLoadsOnlyWhenItsSheetOpens(): void
    {
        $photos = <<<'JS'
            return performance.getEntriesByType('resource')
                .filter(entry => entry.name.indexOf('/demo/') >= 0 && entry.name.indexOf('-thumb') < 0).length;
            JS;
        self::openProgramScreen();

        self::assertSame(0, self::script($photos), 'no photo is fetched while every sheet is closed');

        self::openVolejbal();
        self::waitForFirstPhoto();

        self::assertGreaterThanOrEqual(1, self::script($photos));
    }

    public function testDotsFollowTheSwipe(): void
    {
        self::openProgramScreen();
        self::openVolejbal();
        $dots = '.sheet-body[data-pg-detail="1"] .gallery-dot';
        self::assertTrue(self::script('return document.querySelectorAll(arguments[0])[0].hasAttribute("data-active");', [$dots]));

        self::script('const strip = document.querySelector(\'.sheet-body[data-pg-detail="1"] [data-gallery-strip]\'); strip.scrollLeft = strip.clientWidth;');

        self::waitFor('const d = document.querySelectorAll(arguments[0]); return d[1].hasAttribute("data-active") && !d[0].hasAttribute("data-active");', [$dots]);
    }

    /**
     * Review Focus 2's photo half. The morph has to land, so the sheet is closed (an open
     * one defers it) and the markup has to differ: pairing behind the screen's back changes
     * every sheet footer and adds Moje's strip, whose arrival proves the morph. The photo
     * keeps `is-loaded` throughout: a morph that wrote the server's `class` back would drop
     * it, even if something put it back afterwards.
     */
    public function testAMorphKeepsLoadedPhotosVisible(): void
    {
        self::logOut();
        self::openProgramScreen();
        self::openVolejbal();
        self::waitForFirstPhoto();
        self::tap('.sheet.is-open .sheet-close');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
        self::assertSame(0, self::script('return document.querySelectorAll(\'[data-screen="/korbo26/programy"] [data-pg-pager="list"]\').length;'));
        // Every change to the photo's class from here on. It has is-loaded now, so a record
        // whose old value lacks it means the class was without it for a moment, even if
        // something (gallery.js on screen:morphed) put it back in the same task.
        self::script(<<<'JS'
            const img = document.querySelector(arguments[0]);
            window.__photo = img;
            window.__lostLoaded = false;
            new MutationObserver(records => {
                records.forEach(record => {
                    if ((' ' + record.oldValue + ' ').indexOf(' is-loaded ') < 0) {
                        window.__lostLoaded = true;
                    }
                });
            }).observe(img, {attributes: true, attributeFilter: ['class'], attributeOldValue: true});
            JS, [self::PHOTO]);

        // paired behind the screen's back (the start, then the poll the local source approves)
        self::assertTrue(self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            fetch('/korbo26/profil/propojit', {method: 'POST', credentials: 'same-origin', redirect: 'manual'})
                .then(() => fetch('/korbo26/profil/propojit/stav', {credentials: 'same-origin', cache: 'no-store'}))
                .then(response => response.json())
                .then(body => done(body.status === 'approved'), () => done(false));
            JS));
        self::script("navigator.serviceWorker.dispatchEvent(new MessageEvent('message', {data: {type: 'screen-updated', path: '/korbo26/programy'}}));");
        self::waitFor('return document.querySelector(\'[data-screen="/korbo26/programy"] [data-pg-pager="list"]\') !== null;');

        self::assertTrue(self::script('return document.querySelector(arguments[0]) === window.__photo;', [self::PHOTO]), 'the morph kept the photo element');
        self::assertFalse(self::script('return window.__lostLoaded;'), 'the morph never took is-loaded off the photo');
        self::openVolejbal();
        self::assertTrue(self::script('return document.querySelector(arguments[0]).classList.contains("is-loaded");', [self::PHOTO]));
        self::assertSame('1', self::script('return getComputedStyle(document.querySelector(arguments[0])).opacity;', [self::PHOTO]));
    }

    /**
     * Only the rows of programmes with photos carry a frame: korbo26's demo data gives
     * Volejbal, Speed dating, Matrix and Vzduchovky photos, all listed in Přehled. Every
     * thumbnail loads once it is scrolled to.
     */
    public function testOnlyRowsWithPhotosHaveThumbnails(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../events/korbo26/fixtures/programs.json'), true);
        self::assertIsArray($fixture);
        $withPhotos = [];
        foreach ($fixture as $programme) {
            self::assertIsArray($programme);
            if (($programme['images'] ?? []) !== []) {
                $withPhotos[] = (string) $programme['id'];
            }
        }
        self::openOverview();
        $listed = self::script('return Array.from(document.querySelectorAll(arguments[0] + " .pl-item")).map(row => row.dataset.key);', [self::OVERVIEW]);
        self::assertIsArray($listed);
        $expected = array_values(array_intersect($withPhotos, $listed));
        self::assertSame(['1', '5', '11', '13'], $expected, 'korbo26 lists four programmes with photos');

        $thumbed = self::script('return Array.from(document.querySelectorAll(arguments[0] + " .pl-item.has-thumb")).map(row => row.dataset.key);', [self::OVERVIEW]);
        self::assertSame($expected, $thumbed);
        self::assertSame(4, self::script('return document.querySelectorAll(arguments[0] + " .pl-thumb-frame").length;', [self::OVERVIEW]), 'no frame on a row without photos');
        foreach ($expected as $id) {
            self::loadThumb(self::OVERVIEW . ' .pl-item[data-key="' . $id . '"] img.pl-thumb');
        }
    }

    /** The thumbnail takes a third column: the time and the name stay in the first two, in line with every other row. */
    public function testTheTimeAndNameStayAlignedInEveryRow(): void
    {
        self::openOverview();
        $left = static fn(string $id, string $part): float => (float) self::script(
            'return document.querySelector(arguments[0] + \' .pl-item[data-key="\' + arguments[1] + \'"] \' + arguments[2]).getBoundingClientRect().left;',
            [self::OVERVIEW, $id, $part],
        );

        self::assertEqualsWithDelta($left(self::WITHOUT_PHOTOS, '.pl-name'), $left(self::VZDUCHOVKY, '.pl-name'), 1.0, 'the name');
        self::assertEqualsWithDelta($left(self::WITHOUT_PHOTOS, '.pl-time'), $left(self::VZDUCHOVKY, '.pl-time'), 1.0, 'the time');
        // and the frame sits to the right of the name, not over it
        $frame = self::script('const row = document.querySelector(arguments[0] + \' .pl-item[data-key="13"]\'); return {name: row.querySelector(".pl-name").getBoundingClientRect().right, frame: row.querySelector(".pl-thumb-frame").getBoundingClientRect().left, width: row.querySelector(".pl-thumb-frame").getBoundingClientRect().width};', [self::OVERVIEW]);
        self::assertIsArray($frame);
        self::assertLessThanOrEqual($frame['frame'], $frame['name']);
        self::assertEqualsWithDelta(56, $frame['width'], 0.5);
    }

    /**
     * Review Focus 3: Speed dating (programme 5) running on the reader's own list. The
     * `probíhá` chip and the thumbnail both sit at the row's top right, so the chip moves
     * left of the frame.
     */
    public function testTheRunningChipClearsTheThumbnail(): void
    {
        self::pinClock('2026-09-16T20:10:00+02:00');
        self::pair();
        self::visit('/korbo26/programy/moje');
        $row = '[data-screen="/korbo26/programy"] #pg-view-list .pl-item[data-key="5"]';
        self::waitFor('const row = document.querySelector(arguments[0]); return row !== null && row.dataset.now === "running";', [$row]);

        $geometry = self::script(<<<'JS'
            const row = document.querySelector(arguments[0]);
            const frame = row.querySelector('.pl-thumb-frame').getBoundingClientRect();
            return {
                right: parseFloat(getComputedStyle(row, '::after').right),
                content: getComputedStyle(row, '::after').content,
                rowRight: row.getBoundingClientRect().right,
                frameLeft: frame.left,
                frameWidth: frame.width,
            };
            JS, [$row]);
        self::assertIsArray($geometry);

        self::assertSame('"probíhá"', $geometry['content']);
        self::assertGreaterThanOrEqual($geometry['frameWidth'] + 10, $geometry['right']);
        // the chip's right edge is left of the frame's left edge
        self::assertLessThanOrEqual($geometry['frameLeft'], $geometry['rowRight'] - $geometry['right']);
    }

    /**
     * Review Focus 2's thumbnail half, as testAMorphKeepsLoadedPhotosVisible: a loaded
     * thumbnail in Přehled, a morph forced by pairing behind the screen's back (Moje's strip
     * arrives with it), and the thumbnail keeps `is-loaded` throughout and stays the same
     * element.
     */
    public function testAMorphKeepsALoadedThumbnailVisible(): void
    {
        self::logOut();
        self::openOverview();
        self::loadThumb(self::THUMB);
        self::assertNull(self::script('return document.querySelector(".sheet.is-open");'), 'no sheet defers the morph');
        self::assertSame(0, self::script('return document.querySelectorAll(\'[data-screen="/korbo26/programy"] [data-pg-pager="list"]\').length;'));
        self::script(<<<'JS'
            const img = document.querySelector(arguments[0]);
            window.__thumb = img;
            window.__lostLoaded = false;
            new MutationObserver(records => {
                records.forEach(record => {
                    if ((' ' + record.oldValue + ' ').indexOf(' is-loaded ') < 0) {
                        window.__lostLoaded = true;
                    }
                });
            }).observe(img, {attributes: true, attributeFilter: ['class'], attributeOldValue: true});
            JS, [self::THUMB]);

        self::assertTrue(self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            fetch('/korbo26/profil/propojit', {method: 'POST', credentials: 'same-origin', redirect: 'manual'})
                .then(() => fetch('/korbo26/profil/propojit/stav', {credentials: 'same-origin', cache: 'no-store'}))
                .then(response => response.json())
                .then(body => done(body.status === 'approved'), () => done(false));
            JS));
        self::script("navigator.serviceWorker.dispatchEvent(new MessageEvent('message', {data: {type: 'screen-updated', path: '/korbo26/programy'}}));");
        self::waitFor('return document.querySelector(\'[data-screen="/korbo26/programy"] [data-pg-pager="list"]\') !== null;');

        self::assertTrue(self::script('return document.querySelector(arguments[0]) === window.__thumb;', [self::THUMB]), 'the morph kept the thumbnail element');
        self::assertFalse(self::script('return window.__lostLoaded;'), 'the morph never took is-loaded off the thumbnail');
        self::assertSame('1', self::script('return getComputedStyle(document.querySelector(arguments[0])).opacity;', [self::THUMB]));
    }
}
