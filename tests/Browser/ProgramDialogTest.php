<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Program screen's one dialog, the programme sheet, pushes a history entry with its
 * address (`#section-<s>-program-<id>` on the view's path), so Back closes it instead of
 * leaving the app inert under the focus trap, a reload or a Back from another screen opens
 * it again, and the entry under it never carries the hash; a morph that adds a strip in
 * front of it leaves it a sheet. The old `#muj-program` hashes land on Moje. The days are
 * picked from chips, which open nothing and push nothing.
 */
#[Group('browser')]
final class ProgramDialogTest extends BrowserTestCase
{
    private static function openProgramScreen(): void
    {
        self::visit('/korbo26/');
        self::tap('.tabbar a[href="/korbo26/programy"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); return s && !s.hidden && s.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
    }

    private static function openFirstCard(): void
    {
        self::script('document.querySelector(\'[data-screen="/korbo26/programy"] .tl-page.is-active .tl-card\').click();');
        self::waitFor('return document.querySelector(".sheet.is-open") !== null;');
    }

    /** Drops the session cookie, so no test depends on what the one before it left behind. */
    private static function logOut(): void
    {
        self::visit('/korbo26/');
        self::$browser->getWebDriver()->manage()->deleteAllCookies();
        // and the pages the worker kept for the identity before, as a login's purge would
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

    /**
     * The sheet names when and where in two rows, and its section as a chip: Kde whenever
     * the programme has a place — Volejbal (1) on Volejbalové hřiště — and no Kde row for
     * one without — Speed dating (5).
     */
    public function testTheSheetShowsKdyAndKde(): void
    {
        $facts = <<<'JS'
            return Array.from(document.querySelectorAll(".sheet-body.is-open .sheet-facts > *"))
                .filter(function (el) { return el.getClientRects().length > 0; })
                .map(function (el) { return el.tagName + ":" + el.textContent.trim(); });
            JS;
        self::openProgramScreen();

        self::script('document.querySelector(\'[data-screen="/korbo26/programy"] .tl-card[data-key="1"]\').click();');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="1"]\') !== null;');
        self::assertSame(['DT:Kdy', 'DD:st 16. 9. 8:00–22:00', 'DT:Kde', 'DD:Volejbalové hřiště'], self::script($facts));
        self::assertSame('Pohybová', self::script('const c = document.querySelector(".sheet-body.is-open .sheet-chip"); return c !== null && c.getClientRects().length > 0 ? c.textContent.trim() : null;'));
        self::tap('.sheet.is-open .sheet-close');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');

        self::script('document.querySelector(\'[data-screen="/korbo26/programy"] .tl-card[data-key="5"]\').click();');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="5"]\') !== null;');
        self::assertSame(['DT:Kdy', 'DD:st 16. 9. 20:00–21:00'], self::script($facts));
        self::tap('.sheet.is-open .sheet-close');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
    }

    /** C-C1: Android Back with the sheet open used to leave every other screen inert. */
    public function testBackClosesTheSheetAndLeavesNothingInert(): void
    {
        self::openProgramScreen();
        self::openFirstCard();
        self::script('history.back();');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');

        self::assertSame(0, self::script('return document.querySelectorAll("[inert]").length;'));
        self::assertSame('/korbo26/programy', self::script('return location.pathname;'));
        self::tap('.tabbar a[href="/korbo26/informace"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/informace"]\'); return s && !s.hidden;');
    }

    public function testClosingTheSheetTakesItsHistoryEntryWithIt(): void
    {
        self::openProgramScreen();
        $length = self::script('return history.length;');
        self::openFirstCard();
        self::tap('.sheet.is-open .sheet-close');
        self::waitFor('return document.querySelector(".sheet.is-open") === null && !(history.state && history.state.pgOverlay);');
        // Back now leaves the Program screen for the homepage, as it would have before the sheet
        self::script('history.back();');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/"]\'); return s && !s.hidden;');
        self::assertSame($length + 1, self::script('return history.length;'), 'one entry was pushed for the sheet and nothing more');
    }

    public function testEscapeClosesTheSheetAndGoesBackExactlyOnce(): void
    {
        self::openProgramScreen();
        $length = self::script('return history.length;');
        self::openFirstCard();
        self::assertTrue(self::script('return history.state !== null && history.state.pgOverlay === true;'));
        self::$browser->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
        self::waitFor('return document.querySelector(".sheet.is-open") === null && !(history.state && history.state.pgOverlay);');
        self::assertSame('/korbo26/programy', self::script('return history.state.screen;'), 'back on the screen\'s own entry, not one further');
        self::assertSame($length + 1, self::script('return history.length;'));
        self::assertSame(0, self::script('return document.querySelectorAll("[inert]").length;'));
    }

    public function testBackGivesFocusBackToTheCardThatOpenedTheSheet(): void
    {
        self::openProgramScreen();
        self::script('const card = document.querySelector(\'[data-screen="/korbo26/programy"] .tl-page.is-active .tl-card\'); card.setAttribute("data-test-opener", ""); card.focus(); card.click();');
        self::waitFor('return document.querySelector(".sheet.is-open") !== null;');
        self::script('history.back();');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
        self::assertTrue(self::script('return document.activeElement !== null && document.activeElement.hasAttribute("data-test-opener");'));
    }

    /** Closing and opening again before the close's popstate lands: the late popstate must not close the new sheet. */
    public function testASheetOpenedWhileTheCloseIsStillGoingBackStaysOpen(): void
    {
        self::openProgramScreen();
        self::openFirstCard();
        $second = self::script(<<<'JS'
            window.__pgPops = 0;
            window.addEventListener('popstate', function () { window.__pgPops++; });
            const cards = document.querySelectorAll('[data-screen="/korbo26/programy"] .tl-page.is-active .tl-card');
            const second = cards[cards.length > 1 ? 1 : 0];
            document.querySelector('.sheet.is-open .sheet-close').click();
            second.click();
            return second.dataset.pgOpen;
            JS);
        self::waitFor('return window.__pgPops >= 1 && document.querySelector(".sheet.is-open") !== null;');
        usleep(300_000);
        self::assertSame(1, self::script('return window.__pgPops;'));
        self::assertTrue(self::script('return document.querySelector(".sheet.is-open") !== null && history.state !== null && history.state.pgOverlay === true;'));
        self::assertTrue(self::script('return document.querySelector(\'.sheet-body.is-open[data-pg-detail="\' + arguments[0] + \'"]\') !== null;', [$second]));
    }

    /** Review Focus 4: a notification tap lands on a deep link; Back closes the sheet and stays. */
    public function testBackClosesADeepLinkedSheetAndStaysOnTheScreen(): void
    {
        self::visit('/korbo26/programy');
        $hash = self::script('const b = document.querySelector("[data-pg-detail]"); return "#section-0-program-" + b.dataset.pgDetail;');
        // a fresh landing, the way a notification tap arrives: a document load, not a hash change
        self::visit('/korbo26/');
        self::visit('/korbo26/programy' . $hash);
        self::waitFor('return document.querySelector(".sheet.is-open") !== null;');
        self::script('history.back();');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
        self::assertSame('/korbo26/programy', self::script('return location.pathname;'));
        self::assertSame('', self::script('return location.hash;'), 'the entry under the sheet carries no programme hash');
    }

    /**
     * A sheet opened on landing has no card that opened it: Back puts focus on the
     * programme's card on the view on show, never on <body>, where a screen reader loses
     * its place.
     */
    public function testBackFromALandedSheetFocusesItsCardNotTheBody(): void
    {
        self::visit('/korbo26/programy');
        $id = self::script('return document.querySelector("[data-pg-detail]").dataset.pgDetail;');
        self::visit('/korbo26/');
        self::visit('/korbo26/programy#section-0-program-' . $id);
        self::waitFor('return document.querySelector(".sheet.is-open") !== null;');
        self::script('history.back();');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
        self::assertFalse(self::script('return document.activeElement === null || document.activeElement === document.body;'), 'focus fell to <body>');
        self::assertTrue(self::script('return document.activeElement.matches(\'[data-pg-open="\' + arguments[0] + \'"]\');', [$id]), 'focus is on the programme\'s card');
    }

    /** A day chip in Můj program scrolls to its day, and the chip stays current once the scroll has settled. */
    public function testPickingADayInMyProgramLandsOnIt(): void
    {
        self::pair();
        self::visit('/korbo26/programy/moje');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-view="list"]\') !== null;');
        $current = 'const c = document.querySelector(\'.day-chip[data-pg-kind="list"][aria-current="true"]\'); return c ? c.textContent.trim() : null;';
        $second = self::script('return document.querySelectorAll(\'.day-chip[data-pg-kind="list"]\')[1].textContent.trim();');
        self::script('document.querySelectorAll(\'.day-chip[data-pg-kind="list"]\')[1].click();');
        self::assertFalse(self::script('return !!(history.state && history.state.pgOverlay);'), 'a chip pushes no history entry');

        self::waitFor('return window.scrollY > 0 && (function () { ' . $current . ' })() === arguments[0];', [$second]);
        // the smooth scroll has settled: the same offset on two polls in a row
        self::waitFor(<<<'JS'
            const now = window.scrollY;
            const was = window.__pgLastScroll;
            window.__pgLastScroll = now;
            return was === now;
            JS);
        self::assertSame($second, self::script($current), 'the scroll did not land on the day');
    }

    /** The old `#muj-program` (a login from the list's own form, an old bookmark) lands on Moje's own address. */
    public function testAnOldMujProgramHashLandsInMoje(): void
    {
        self::visit('/korbo26/programy#muj-program');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"][data-view="list"]\') !== null;');

        self::assertSame('/korbo26/programy/moje', self::script('return location.pathname;'));
        self::assertSame('', self::script('return location.hash;'));
        self::assertNull(self::script('return document.querySelector(".sheet.is-open");'));
    }

    /** The old `#muj-program-<id>` (Domů's card before it had the sheet's address) lands on that sheet in Moje. */
    public function testAnOldMujProgramIdHashLandsOnThatSheetInMoje(): void
    {
        self::pair();
        self::visit('/korbo26/programy#muj-program-5');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="5"]\') !== null;');

        self::assertSame('/korbo26/programy/moje#section-6-program-5', self::script('return location.pathname + location.hash;'));
        self::assertSame('list', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
        // a real click inside the card, so the browser's Back honours the entry the sheet pushed
        self::tap('.sheet.is-open .sheet-body.is-open .sheet-name');
        self::$browser->back();
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
        self::assertSame('/korbo26/programy/moje', self::script('return location.pathname + location.hash;'));
        self::assertSame('list', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
    }

    // --- the sheet's address --------------------------------------------

    public function testOpeningASheetPutsItsAddressInTheUrl(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::script('document.querySelector(\'.tl-card[data-key="1"]\').click();');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="1"]\') !== null;');

        self::assertSame('/korbo26/programy#section-1-program-1', self::script('return location.pathname + location.hash;'));
        self::assertTrue(self::script('return history.state !== null && history.state.pgOverlay === true;'));
    }

    public function testASheetOpenedInMojeKeepsMojesPath(): void
    {
        self::pair();
        self::visit('/korbo26/programy/moje');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"][data-view="list"]\') !== null;');
        self::script('document.querySelector(\'.view-list .pl-item[data-key="5"] .pl-open\').click();');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="5"]\') !== null;');

        self::assertSame('/korbo26/programy/moje#section-6-program-5', self::script('return location.pathname + location.hash;'));
    }

    /** A reload of the sheet's entry reopens the sheet on that entry, pushing nothing. */
    public function testAReloadWithTheSheetsHashReopensIt(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::tap('.tl-card[data-key="1"]');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="1"]\') !== null;');
        $length = self::script('return history.length;');
        self::script('window.__beforeReload = 1;');

        self::$browser->getWebDriver()->navigate()->refresh();
        self::waitFor('return !window.__beforeReload && document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="1"]\') !== null;');

        self::assertSame('/korbo26/programy#section-1-program-1', self::script('return location.pathname + location.hash;'));
        self::assertSame($length, self::script('return history.length;'));
        self::script('history.back();');
        self::waitFor('return document.readyState === "complete" && location.hash === "" && document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::assertNull(self::script('return document.querySelector(".sheet.is-open");'));
        self::assertSame('/korbo26/programy', self::script('return location.pathname;'));
    }

    /**
     * A same-document hash change, as the worker's client.navigate() makes from a
     * notification on a reader already on the screen: the sheet opens with the timeline on
     * the programme's page, not on the page that was up.
     */
    public function testAHashChangeOnTheScreenOpensTheSheetOnItsPage(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        $other = self::script(<<<'JS'
            const active = document.querySelector('.tl-page.is-active').dataset.key;
            const body = Array.from(document.querySelectorAll('.sheet-body')).find(b => b.dataset.page && b.dataset.page !== active);
            return {id: body.dataset.pgDetail, hash: '#' + body.id, page: body.dataset.page};
            JS);

        self::script('location.hash = arguments[0];', [$other['hash']]);

        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="\' + arguments[0] + \'"]\') !== null;', [$other['id']]);
        self::assertSame($other['page'], self::script('return document.querySelector(".tl-page.is-active").dataset.key;'));
        self::assertSame('/korbo26/programy' . $other['hash'], self::script('return location.pathname + location.hash;'));
        self::assertTrue(self::script('return history.state !== null && history.state.pgOverlay === true;'));
    }

    /** An old `#muj-program-<id>` arriving as a hash change is rewritten to Moje's address too. */
    public function testAnOldMujProgramIdHashChangeLandsOnThatSheetInMoje(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');

        self::script('location.hash = "#muj-program-5";');

        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="5"]\') !== null;');
        self::assertSame('/korbo26/programy/moje#section-6-program-5', self::script('return location.pathname + location.hash;'));
        self::assertSame('list', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
    }

    /**
     * The sheet's own link to another screen (the tab bar is inert under it): the sheet
     * closes there, and Back to its entry opens it again.
     */
    public function testSheetThenAnotherScreenThenBackReopensTheSheet(): void
    {
        self::toProfilFromTheSheet();

        self::$browser->back();
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); return !!s && !s.hidden && document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="1"]\') !== null;');
        self::assertSame('/korbo26/programy#section-1-program-1', self::script('return location.pathname + location.hash;'));
        self::assertTrue(self::script('return document.querySelector(".sheet.is-open [data-pg-sheet-card]").contains(document.activeElement);'));
    }

    public function testATabTapToProgramLeavesTheSheetClosed(): void
    {
        self::toProfilFromTheSheet();

        self::tap('.tabbar a[href="/korbo26/programy"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); return !!s && !s.hidden;');
        self::assertSame('/korbo26/programy', self::script('return location.pathname + location.hash;'));
        self::assertNull(self::script('return document.querySelector(".sheet.is-open");'));
    }

    /** Logged out, programme 1's sheet open, then its link to Profil: Profil on show, the sheet closed, nothing inert. */
    private static function toProfilFromTheSheet(): void
    {
        self::logOut();
        self::openProgramScreen();
        self::tap('[data-screen="/korbo26/programy"] .tl-card[data-key="1"]');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="1"]\') !== null;');
        self::assertFalse(self::script('return document.querySelector(\'.sheet-body.is-open a[data-key="unpaired"]\').closest("[inert]") !== null;'));

        self::tap('.sheet-body.is-open a[data-key="unpaired"]');

        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/profil"]\'); return !!s && !s.hidden && location.pathname === "/korbo26/profil";');
        self::assertNull(self::script('return document.querySelector(".sheet.is-open");'));
        self::assertSame(0, self::script('return document.querySelectorAll("[inert]").length;'));
    }

    /** C-I1: a morph that adds the list's strip in front of the sheet must leave the sheet a sheet. */
    public function testAMorphThatAddsTheListPagerKeepsTheSheetASheet(): void
    {
        self::logOut();
        self::openProgramScreen();
        self::assertSame(0, self::script('return document.querySelectorAll(\'[data-screen="/korbo26/programy"] [data-pg-pager="list"]\').length;'));
        // paired behind the screen's back (the start, then the poll the local source
        // approves): the next revalidation renders the list's strip
        self::assertTrue(self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            fetch('/korbo26/profil/propojit', {method: 'POST', credentials: 'same-origin', redirect: 'manual'})
                .then(() => fetch('/korbo26/profil/propojit/stav', {credentials: 'same-origin', cache: 'no-store'}))
                .then(response => response.json())
                .then(body => done(body.status === 'approved'), () => done(false));
            JS));
        self::script("navigator.serviceWorker.dispatchEvent(new MessageEvent('message', {data: {type: 'screen-updated', path: '/korbo26/programy'}}));");
        self::waitFor('return document.querySelector(\'[data-screen="/korbo26/programy"] [data-pg-pager="list"]\') !== null;');

        self::openFirstCard();
        self::assertSame(1, self::script('return document.querySelectorAll(".sheet-body.is-open").length;'));
        self::assertTrue(self::script('return document.querySelector(\'[data-screen="/korbo26/programy"] [data-key="sheet"]\').classList.contains("is-open");'));
    }
}
