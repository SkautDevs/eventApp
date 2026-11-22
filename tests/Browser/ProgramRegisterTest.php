<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\Group;

/**
 * Registration from the sheet, in a real Chrome on korbo26, paired: shell/write.js sends
 * the form, the server answers with the programme's deep link on the view the reader acted
 * from (the form's `pohled` field), and the document that lands in place of the sheet's
 * entry reopens the sheet under the toast that says what happened. The
 * toast stays usable over the open sheet, by pointer and by keyboard, and the sheet's own
 * close button stays clear of it.
 *
 * Every test starts from the fixtures' registrations (resetStubState()), never from what
 * the test before it tapped, so one failure does not take the rest of the class with it.
 */
#[Group('browser')]
final class ProgramRegisterTest extends BrowserTestCase
{
    /** Volejbal, on the first day's page: not the fixture participant's, not preregistered. */
    private const string ID = '1';

    /** Speed dating: one of the fixture participant's own, not preregistered. */
    private const string MINE = '5';

    protected function setUp(): void
    {
        parent::setUp();
        self::resetStubState();
    }

    private static function openProgramScreen(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
    }

    private static function openSheet(string $id = self::ID): void
    {
        self::openProgramScreen();
        self::script('document.querySelector(\'.tl-card[data-key="' . $id . '"]\').click();');
        self::waitForSheet($id);
    }

    private static function waitForSheet(string $id): void
    {
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="' . $id . '"]\') !== null;');
    }

    /** Waits for the landing after a form post: the toast up and the programme's sheet open again. */
    private static function waitForLanding(string $title, string $id = self::ID): void
    {
        self::waitFor(
            'const t = document.querySelector("[data-toast] strong"); const b = document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="' . $id . '"]\');'
            . ' return document.readyState === "complete" && t !== null && t.textContent.indexOf(arguments[0]) === 0 && b !== null;',
            [$title],
            20,
        );
    }

    public function testRegisteringAndUnregisteringFromTheSheet(): void
    {
        self::pair();
        self::openSheet();
        self::assertStringNotContainsString('is-registered', self::script('return document.querySelector(\'.tl-card[data-key="' . self::ID . '"]\').className;'));

        self::tap('.sheet-body.is-open .sheet-footer button');
        self::waitForLanding('Zapsáno: ');
        self::assertSame('Zapsáno: Volejbal', self::script('return document.querySelector("[data-toast] strong").textContent;'));
        self::assertSame('St 16. 9. · 8:00–22:00 · Volejbalové hřiště', self::script('return document.querySelector("[data-toast] span").textContent;'));
        // the sheet says the time the way the toast does
        self::assertSame('st 16. 9. 8:00–22:00', self::script('return document.querySelector(".sheet-body.is-open .sheet-facts dd").textContent;'));
        self::assertStringContainsString('is-registered', self::script('return document.querySelector(\'.tl-card[data-key="' . self::ID . '"]\').className;'));
        self::assertStringContainsString('#section-1-program-1', self::script('return location.href;'));
        // acted from the timeline, landed on the timeline
        self::assertSame('timeline', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));

        // the unregister asks first: the first tap only changes the label
        self::tap('.sheet-body.is-open .sheet-footer button');
        self::assertSame('Opravdu zrušit zápis?', self::script('return document.querySelector(".sheet-body.is-open .sheet-footer button").textContent;'));
        self::tap('.sheet-body.is-open .sheet-footer button');
        self::waitForLanding('Zápis zrušen: ');
        self::assertSame('Zápis zrušen: Volejbal', self::script('return document.querySelector("[data-toast] strong").textContent;'));
        self::assertSame('Místo jsme uvolnili', self::script('return document.querySelector("[data-toast] span").textContent;'));
        self::assertStringNotContainsString('is-registered', self::script('return document.querySelector(\'.tl-card[data-key="' . self::ID . '"]\').className;'));
    }

    /** A first tap on the unregister asks; closing the sheet withdraws the question. */
    public function testClosingTheSheetResetsTheUnregisterQuestion(): void
    {
        self::pair();
        self::openProgramScreen();
        $button = '.sheet-body.is-open .sheet-footer button';
        $open = 'document.querySelector(\'.tl-card[data-key="' . self::MINE . '"]\').click();';

        self::script($open);
        self::waitForSheet(self::MINE);
        self::assertSame('Zrušit zápis', self::script('return document.querySelector(arguments[0]).textContent;', [$button]));
        self::tap($button);
        self::assertSame('Opravdu zrušit zápis?', self::script('return document.querySelector(arguments[0]).textContent;', [$button]));

        self::tap('.sheet.is-open .sheet-close');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
        self::script($open);
        self::waitForSheet(self::MINE);
        self::assertSame('Zrušit zápis', self::script('return document.querySelector(arguments[0]).textContent;', [$button]));
        self::assertFalse(self::script('return document.querySelector(arguments[0]).hasAttribute("data-confirming");', [$button]));

        self::tap('.sheet.is-open .sheet-close');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
    }

    public function testTheToastAndTheSheetCloseIndependently(): void
    {
        self::pair();
        self::openSheet();
        self::tap('.sheet-body.is-open .sheet-footer button');
        self::waitForLanding('Zapsáno: ');

        // the toast is over the open sheet and not inert, and it covers nothing of the close button
        $layout = self::script(<<<'JS'
            const toast = document.querySelector('[data-toast]').getBoundingClientRect();
            const close = document.querySelector('.sheet.is-open .sheet-close').getBoundingClientRect();
            const x = toast.left + toast.width / 2;
            const y = toast.top + toast.height / 2;
            const hit = document.elementFromPoint(x, y);
            return {
                onTop: hit !== null && hit.closest('[data-toast]') !== null,
                inert: document.querySelector('[data-toast]').closest('[inert]') !== null,
                overlaps: !(toast.bottom <= close.top || toast.top >= close.bottom),
            };
            JS);
        self::assertTrue($layout['onTop'], 'the toast is painted under the sheet');
        self::assertFalse($layout['inert'], 'the sheet\'s trap made the toast inert');
        self::assertFalse($layout['overlaps'], 'the toast covers the sheet\'s close button');

        self::tap('[data-toast] [data-toast-close]');
        self::waitFor('return document.querySelector("[data-toast]") === null;');
        self::assertNotNull(self::script('return document.querySelector(".sheet.is-open");'), 'closing the toast closed the sheet');
        // the focus stays inside the dialog
        self::assertTrue(self::script('return document.querySelector(".sheet.is-open [data-pg-sheet-card]").contains(document.activeElement);'));

        self::tap('.sheet.is-open .sheet-close');
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
        self::assertSame(0, self::script('return document.querySelectorAll("[inert]").length;'));
    }

    /**
     * With the sheet open everything else is inert and Tab rings inside the card — but the
     * toast over it is live, and its close button must be in that ring, or a keyboard could
     * never close it while the sheet is up.
     */
    public function testTheToastsCloseButtonIsInTheSheetsTabRing(): void
    {
        self::pair();
        self::openSheet();
        self::tap('.sheet-body.is-open .sheet-footer button');
        self::waitForLanding('Zapsáno: ');

        $visited = [];
        for ($i = 0; $i < 8; $i++) {
            self::$browser->getKeyboard()->sendKeys(WebDriverKeys::TAB);
            $visited[] = self::script(<<<'JS'
                const here = document.activeElement;
                if (here.closest('[data-toast]')) { return 'toast:' + here.className; }
                return document.querySelector('.sheet.is-open [data-pg-sheet-card]').contains(here) ? 'sheet' : 'outside:' + here.tagName;
                JS);
        }
        self::assertContains('toast:toast-close', $visited, implode(', ', $visited));
        self::assertSame([], array_values(array_filter($visited, static fn(string $where): bool => str_starts_with($where, 'outside'))), implode(', ', $visited));

        // and Enter on it closes the toast, leaving the sheet open with the focus in it
        self::script('document.querySelector("[data-toast] [data-toast-close]").focus();');
        self::$browser->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
        self::waitFor('return document.querySelector("[data-toast]") === null;');
        self::assertTrue(self::script('return document.querySelector(".sheet.is-open [data-pg-sheet-card]").contains(document.activeElement);'));
    }

    /** Acted from Přehled: the landing is Přehled again, on that programme, with its sheet open. */
    public function testARegistrationFromPrehledLandsOnPrehled(): void
    {
        self::pair();
        self::openProgramScreen();
        self::script('document.querySelector(\'[data-pg-view="overview"]\').click();');
        self::waitFor('return document.querySelector("[data-pg-root]").dataset.view === "overview";');
        self::script('document.querySelector(\'.view-overview .pl-item[data-key="' . self::ID . '"] .pl-open\').click();');
        self::waitForSheet(self::ID);

        self::tap('.sheet-body.is-open .sheet-footer button');
        self::waitForLanding('Zapsáno: ');
        self::assertSame('overview', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
        self::assertSame('true', self::script('return document.querySelector(\'[data-pg-view="overview"]\').getAttribute("aria-selected");'));
        self::assertSame('/korbo26/programy/prehled#section-1-program-1', self::script('return location.pathname + location.hash;'));
        // the row is on screen under the sheet, and says it is the reader's now
        self::waitFor('const r = document.querySelector(\'.view-overview .pl-item[data-key="' . self::ID . '"]\').getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight;');
        self::assertStringContainsString('is-registered', self::script('return document.querySelector(\'.view-overview .pl-item[data-key="' . self::ID . '"]\').className;'));
    }

    /** Acted from Moje: the landing is Moje again, with the sheet open on the programme just left. */
    public function testAnUnregistrationFromMojeLandsOnMoje(): void
    {
        self::pair();
        self::openProgramScreen();
        self::script('document.querySelector(\'[data-pg-view="list"]\').click();');
        self::waitFor('return document.querySelector("[data-pg-root]").dataset.view === "list";');
        self::script('document.querySelector(\'.view-list .pl-item[data-key="' . self::MINE . '"] .pl-open\').click();');
        self::waitForSheet(self::MINE);

        self::tap('.sheet-body.is-open .sheet-footer button');
        self::tap('.sheet-body.is-open .sheet-footer button');
        self::waitForLanding('Zápis zrušen: ', self::MINE);
        self::assertSame('/korbo26/programy/moje', self::script('return location.pathname;'));
        self::assertSame('list', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
        self::assertSame('true', self::script('return document.querySelector(\'[data-pg-view="list"]\').getAttribute("aria-selected");'));
        // it has left Moje, and the sheet now offers the registration back
        self::assertNull(self::script('return document.querySelector(\'.view-list .pl-item[data-key="' . self::MINE . '"]\');'));
        self::assertSame('Zapsat program', self::script('return document.querySelector(".sheet-body.is-open .sheet-footer button").textContent;'));
    }

    /**
     * Review Focus: a write that does not reload. shell/write.js sends the form with
     * `X-Write: 1` and lands on the answer's `next`, here the sheet's own address: a new
     * document (the marker set before the tap is gone) on the sheet's entry, replaced, not
     * one more. Back closes the sheet onto the screen under it, which says the programme is
     * the reader's now: no stale `Zapsat program` from before the write.
     */
    public function testRegisteringReplacesTheEntryAndBackShowsNoStaleSheet(): void
    {
        self::pair();
        self::openProgramScreen();
        $n = self::script('return history.length;');
        self::script('document.querySelector(\'.tl-card[data-key="' . self::ID . '"]\').click();');
        self::waitForSheet(self::ID);
        self::script('window.__old = true;');

        self::tap('.sheet-body.is-open .sheet-footer button');
        self::waitForLanding('Zapsáno: ');
        self::assertNull(self::script('return window.__old === undefined ? null : window.__old;'), 'the write did not load a new document');
        self::assertSame($n + 1, self::script('return history.length;'), 'the write added a history entry of its own');
        self::assertSame('/korbo26/programy#section-1-program-1', self::script('return location.pathname + location.hash;'));

        self::script('history.back();');
        self::waitFor('return document.readyState === "complete" && location.hash === "" && document.querySelector(".sheet.is-open") === null && document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::assertSame('/korbo26/programy', self::script('return location.pathname;'));
        self::assertSame('Zrušit zápis', self::script('return document.querySelector(\'.sheet-body[data-pg-detail="' . self::ID . '"] .sheet-footer button\').textContent;'));
    }

    /**
     * A second tap while the write is on its way would send it twice: the button is
     * disabled until the answer, the form carries the view on show, and a write that cannot
     * leave (the fetch rejects: no worker, no network) gets the button back with the offline
     * toast. A page shown again from the back-forward cache leaves it working.
     */
    public function testTheButtonIsDisabledWhileTheWriteIsOnItsWayAndEnabledOnFailure(): void
    {
        self::pair();
        self::openSheet();
        // only the write is held: the loader's warm-up may fetch meanwhile
        self::script('const original = window.fetch; window.fetch = (input, init) => (init && init.method === "POST" ? new Promise((_, reject) => { window.__reject = reject; }) : original(input, init));');
        $button = '.sheet-body.is-open .sheet-footer button';

        self::tap($button);
        self::waitFor('return typeof window.__reject === "function";');
        self::assertTrue(self::script('return document.querySelector(arguments[0]).disabled;', [$button]));
        // the form carries the view on show, for the answer's next
        self::assertSame('timeline', self::script('return document.querySelector(\'.sheet-body.is-open input[name="pohled"]\').value;'));

        self::script('window.__reject(new TypeError("offline"));');
        self::waitFor(
            'const t = document.querySelector("[data-toast] strong"); return !document.querySelector(arguments[0]).disabled && t !== null && t.textContent === arguments[1];',
            [$button, 'Akci není možné provést, protože jsi offline.'],
        );
        // still the same document, on the sheet
        self::assertNotNull(self::script('return document.querySelector(".sheet.is-open");'));

        self::script('window.dispatchEvent(new PageTransitionEvent("pageshow", {persisted: true}));');
        self::assertFalse(self::script('return document.querySelector(arguments[0]).disabled;', [$button]));
    }

    /**
     * Another device unregisters the programme and a background revalidation morphs the
     * screen: the footer's unregister form becomes the register form. The morph removes no
     * attribute the fresh markup lacks, so a patched form would keep the unregister's
     * data-confirm and ask "Opravdu zrušit zápis?" on a registration. The forms are keyed by
     * state, and one replaces the other.
     */
    public function testAMorphFromUnregisterToRegisterAsksNothing(): void
    {
        self::pair();
        self::visit('/korbo26/');
        self::tap('.tabbar a[href="/korbo26/programy"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); return s && !s.hidden && s.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        $footer = '[data-screen="/korbo26/programy"] [data-pg-detail="' . self::MINE . '"] .sheet-footer';
        self::assertTrue(self::script('return document.querySelector(arguments[0] + " button[data-confirm]") !== null;', [$footer]));

        self::writeStubRegistrations('korbo26', ['1' => [10, 26, 31, 45, 50]]);
        self::script("navigator.serviceWorker.dispatchEvent(new MessageEvent('message', {data: {type: 'screen-updated', path: '/korbo26/programy'}}));");

        self::waitFor('return document.querySelector(arguments[0] + \' form[action$="/prihlasit"]\') !== null;', [$footer]);
        self::assertSame('Zapsat program', self::script('return document.querySelector(arguments[0] + " button").textContent;', [$footer]));
        self::assertFalse(self::script('return document.querySelector(arguments[0] + " button").hasAttribute("data-confirm");', [$footer]));
    }
}
