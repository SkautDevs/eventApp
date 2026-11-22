<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\Group;

/**
 * The history the loader writes. A reader who lands on a screen from outside the event (a
 * notification, a shared link, a bookmark) finds Domů under it, so Back stays in the app;
 * from there on every entry is a screen the reader went to, in the order they went. A
 * reload, a link from inside the event and Domů itself insert nothing. On the Program
 * screen the view is the address: a pill pushes it, Back walks the views, and the Program
 * tab comes back to the view the reader left.
 */
#[Group('browser')]
final class HistoryTest extends BrowserTestCase
{
    private const string SHOWN = 'const s = document.querySelector(\'[data-screen="\' + arguments[0] + \'"]\'); return location.pathname === arguments[0] && !!s && !s.hidden;';

    /** The Program screen on show at the address arguments[0], on the view arguments[1]. */
    private const string VIEW = 'const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); const root = s && s.querySelector(\'[data-pg-root][data-pg-ready="1"]\'); return location.pathname === arguments[0] && !!root && !s.hidden && root.dataset.view === arguments[1];';

    private const string PILL = '[data-screen="/korbo26/programy"] [data-pg-view="%s"]';

    private const string PROGRAM_TAB = '.tabbar a[href="/korbo26/programy"]';

    private const string INFO_TAB = '.tabbar a[href="/korbo26/informace"]';

    /** A document that has finished loading and has the loader's state on its entry. */
    private static function waitForLoaded(string $path): void
    {
        self::waitFor('return document.readyState === "complete" && location.pathname === arguments[0] && !!history.state && typeof history.state.screen === "string";', [$path]);
    }

    /**
     * Chrome lets the browser's own Back skip an entry added without user activation. The
     * tap, on a heading of the screen, which is no link (the app bar's title is inside
     * one), covers only a reader who has activated the page. ChromeDriver's back() may
     * not exercise the intervention at all, so these tests do not prove the case of a
     * reader who only scrolled (a scroll grants no activation) before pressing Back.
     */
    private static function touch(): void
    {
        self::tap('[data-screen="/korbo26/informace"] h2');
    }

    /** Opened on Informace: one Back is Domů, from the copy the warm-up or the worker has. */
    public function testAPageOpenedFromOutsideHasDomuUnderIt(): void
    {
        self::visit('/korbo26/informace');
        self::waitForLoaded('/korbo26/informace');
        self::script('window.__noReload = 1;');
        self::touch();
        self::assertSame('/korbo26/informace', self::script('return location.pathname;'), 'the touch navigated nowhere');

        self::$browser->back();

        self::waitFor(self::SHOWN, ['/korbo26/']);
        self::assertSame('/korbo26/', self::script('return history.state.screen;'));
        self::assertSame(1, self::script('return window.__noReload;'), 'Domů was shown by the loader, not loaded as a document');
    }

    public function testHistoryIsChronologicalWithDomuAtTheBottom(): void
    {
        self::visit('/korbo26/informace');
        self::waitForLoaded('/korbo26/informace');
        self::script('window.__noReload = 1;');

        self::tap('.tabbar a[href="/korbo26/programy"]');
        self::waitFor(self::SHOWN, ['/korbo26/programy']);

        self::$browser->back();
        self::waitFor(self::SHOWN, ['/korbo26/informace']);

        self::$browser->back();
        self::waitFor(self::SHOWN, ['/korbo26/']);
        self::assertSame(1, self::script('return window.__noReload;'));
    }

    public function testAReloadInsertsNothing(): void
    {
        self::visit('/korbo26/informace');
        self::waitForLoaded('/korbo26/informace');
        $length = self::script('return history.length;');
        self::script('window.__beforeReload = 1;');

        self::$browser->getWebDriver()->navigate()->refresh();
        self::waitFor('return document.readyState === "complete" && !window.__beforeReload;');
        self::waitForLoaded('/korbo26/informace');

        self::assertSame($length, self::script('return history.length;'));
        self::assertSame('/korbo26/informace', self::script('return history.state.screen;'));
    }

    public function testALinkFromInsideTheEventInsertsNothing(): void
    {
        self::visit('/korbo26/');
        self::waitForLoaded('/korbo26/');
        $length = self::script('return history.length;');
        self::script('window.__before = 1; location.assign("/korbo26/informace");');
        self::waitFor('return !window.__before;');
        self::waitForLoaded('/korbo26/informace');

        self::assertSame($length + 1, self::script('return history.length;'), 'one entry for the new document and nothing under it');

        self::$browser->back();
        self::waitFor('return location.pathname === "/korbo26/";');
    }

    public function testDomuOpenedDirectlyInsertsNothing(): void
    {
        // a page outside the event first, so the length has a known starting point
        self::visit('/');
        self::waitFor('return document.readyState === "complete" && location.pathname === "/";');
        $length = self::script('return history.length;');

        self::visit('/korbo26/');
        self::waitForLoaded('/korbo26/');

        self::assertSame('/korbo26/', self::script('return history.state.screen;'));
        self::assertSame($length + 1, self::script('return history.length;'), 'the new document\'s entry and no Domů under Domů');
    }

    public function testAPillTapChangesTheAddressAndBackWalksTheViewsThenLeaves(): void
    {
        self::openProgram();

        self::tap(sprintf(self::PILL, 'overview'));
        self::waitFor(self::VIEW, ['/korbo26/programy/prehled', 'overview']);
        self::tap(sprintf(self::PILL, 'list'));
        self::waitFor(self::VIEW, ['/korbo26/programy/moje', 'list']);

        self::$browser->back();
        self::waitFor(self::VIEW, ['/korbo26/programy/prehled', 'overview']);
        self::$browser->back();
        self::waitFor(self::VIEW, ['/korbo26/programy', 'timeline']);
        self::$browser->back();
        self::waitFor(self::SHOWN, ['/korbo26/']);
        self::assertSame(1, self::script('return window.__noReload;'), 'every step was the loader\'s, no document was loaded');
    }

    public function testReselectingTheShownViewPushesNothing(): void
    {
        self::openProgram();
        $length = self::script('return history.length;');

        self::tap(sprintf(self::PILL, 'timeline'));

        self::waitFor(self::VIEW, ['/korbo26/programy', 'timeline']);
        self::assertSame($length, self::script('return history.length;'));
    }

    public function testTheProgramTabReopensTheLastView(): void
    {
        self::openProgram();
        self::tap(sprintf(self::PILL, 'list'));
        self::waitFor(self::VIEW, ['/korbo26/programy/moje', 'list']);

        self::tap(self::INFO_TAB);
        self::waitFor(self::SHOWN, ['/korbo26/informace']);
        self::tap(self::PROGRAM_TAB);

        self::waitFor(self::VIEW, ['/korbo26/programy/moje', 'list']);
        self::assertSame('true', self::script('return document.querySelector(arguments[0]).getAttribute("aria-selected");', [sprintf(self::PILL, 'list')]));
    }

    /** Review Focus 4: a view switched with the arrow keys is the one the tab comes back to. */
    public function testTheProgramTabReopensAViewChosenByKeyboard(): void
    {
        self::openProgram();
        self::script('document.querySelector(arguments[0]).focus();', [sprintf(self::PILL, 'timeline')]);
        self::$browser->getKeyboard()->sendKeys(WebDriverKeys::ARROW_LEFT);
        self::waitFor(self::VIEW, ['/korbo26/programy/prehled', 'overview']);
        self::assertTrue(self::script('return document.activeElement === document.querySelector(arguments[0]);', [sprintf(self::PILL, 'overview')]));

        self::tap(self::INFO_TAB);
        self::waitFor(self::SHOWN, ['/korbo26/informace']);
        self::tap(self::PROGRAM_TAB);

        self::waitFor(self::VIEW, ['/korbo26/programy/prehled', 'overview']);
    }

    public function testATapOnTheCurrentTabKeepsTheView(): void
    {
        self::openProgram();
        self::tap(sprintf(self::PILL, 'list'));
        self::waitFor(self::VIEW, ['/korbo26/programy/moje', 'list']);
        // logged out, Moje is one line, too short to scroll
        self::script('document.body.style.minHeight = "3000px"; window.scrollTo(0, 400);');
        self::waitFor('return window.scrollY > 0;');

        self::tap(self::PROGRAM_TAB);

        self::waitFor('return window.scrollY === 0;');
        self::assertSame('/korbo26/programy/moje', self::script('return location.pathname;'));
        self::assertSame('list', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
    }

    public function testAViewAddressOpenedFreshPaintsThatView(): void
    {
        self::visit('/korbo26/programy/prehled');
        self::waitForLoaded('/korbo26/programy/prehled');

        self::waitFor(self::VIEW, ['/korbo26/programy/prehled', 'overview']);
        self::assertSame('true', self::script('return document.querySelector(arguments[0]).getAttribute("aria-selected");', [sprintf(self::PILL, 'overview')]));
    }

    /**
     * Review Focus 1: a deep link opened fresh puts its sheet on an entry of its own over the
     * address without the hash (Domů under both). Back closes the sheet, the next Back is
     * Domů; neither step opens the sheet again.
     */
    public function testBackFromADeepLinkedSheetClosesItThenGoesHome(): void
    {
        self::visit('/korbo26/programy/moje#section-6-program-5');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="5"]\') !== null;');
        self::script('window.__noReload = 1; window.__opens = 0; new MutationObserver(function (records) { records.forEach(function (r) { if (r.target.classList.contains("is-open")) { window.__opens++; } }); }).observe(document.querySelector("[data-pg-sheet]"), {attributes: true, attributeFilter: ["class"]});');
        // a real click inside the card, so the browser's Back honours the entries the landing wrote
        self::tap('.sheet.is-open .sheet-body.is-open .sheet-name');

        self::$browser->back();
        self::waitFor(self::VIEW, ['/korbo26/programy/moje', 'list']);
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
        self::assertSame('', self::script('return location.hash;'));

        self::$browser->back();
        self::waitFor(self::SHOWN, ['/korbo26/']);
        self::assertNull(self::script('return document.querySelector(".sheet.is-open");'));
        self::assertSame(0, self::script('return window.__opens;'), 'no Back opened the sheet again');
        self::assertSame(1, self::script('return window.__noReload;'));
    }

    /** Domů, then the Program tab: the Program screen on Harmonogram, shown by the loader. */
    private static function openProgram(): void
    {
        self::visit('/korbo26/');
        self::waitForLoaded('/korbo26/');
        self::script('window.__noReload = 1;');
        self::tap(self::PROGRAM_TAB);
        self::waitFor(self::VIEW, ['/korbo26/programy', 'timeline']);
    }
}
