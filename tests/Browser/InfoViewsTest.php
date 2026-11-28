<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * Informace's two views on obrok19, paired, with JavaScript (www/js/inbox/views.js): the
 * Zprávy pill pushes `/informace/zpravy` without a document load, Back walks the views, a
 * tab tap comes back to the view the screen was left on, and a landing on the Zprávy
 * address paints the Zprávy pane.
 */
#[Group('browser')]
final class InfoViewsTest extends BrowserTestCase
{
    private const string ROOT = 'document.querySelector("[data-screen=\'/obrok19/informace\'] [data-info-root]")';

    /** Whether the pane of `view` is the one on show, and the other one is not. */
    private const string SHOWN = <<<'JS'
        const root = document.querySelector("[data-screen='/obrok19/informace'] [data-info-root]");
        if (root === null || root.dataset.infoReady !== "1") { return false; }
        const on = document.getElementById("info-view-" + arguments[0]);
        const off = document.getElementById("info-view-" + (arguments[0] === "info" ? "inbox" : "info"));
        return root.dataset.view === arguments[0] && on.getClientRects().length > 0 && off.getClientRects().length === 0;
        JS;

    public function testThePillSwitchesInPlaceAndBackReturns(): void
    {
        self::pair('obrok19');
        self::visit('/obrok19/informace');
        self::waitFor(self::SHOWN, ['info']);
        // a mark on the window: a document load would take it away
        self::script('window.infoViewsMark = 1;');
        $length = self::script('return history.length;');

        self::tap('#info-tab-inbox');

        self::waitFor(self::SHOWN, ['inbox']);
        self::assertSame('/obrok19/informace/zpravy', self::script('return location.pathname;'));
        self::assertSame(1, self::script('return window.infoViewsMark;'), 'no document load');
        self::assertSame($length + 1, self::script('return history.length;'), 'one entry for the view');
        self::assertEquals(['screen' => '/obrok19/informace', 'infoView' => 'inbox'], self::script('return history.state;'));
        self::assertTrue(self::script('return document.querySelector("form[data-inbox-form] textarea").getClientRects().length > 0;'), 'the form is on show');
        self::assertSame('page', self::script('return document.getElementById("info-tab-inbox").getAttribute("aria-current");'));
        self::assertFalse(self::script('return document.getElementById("info-tab-info").hasAttribute("aria-current");'));
        // the Informace tab stays lit on the second address
        self::assertTrue(self::script('return document.querySelector(\'.tabbar a[href="/obrok19/informace"]\').classList.contains("is-active");'));

        self::$browser->back();

        self::waitFor('return location.pathname === "/obrok19/informace";');
        self::waitFor(self::SHOWN, ['info']);
        self::assertSame(1, self::script('return window.infoViewsMark;'), 'Back loaded no document');
        self::assertSame('true', self::script('return document.getElementById("info-tab-info").getAttribute("aria-selected");'));
    }

    public function testATabTapComesBackToTheZpravyView(): void
    {
        self::pair('obrok19');
        self::visit('/obrok19/informace');
        self::waitFor(self::SHOWN, ['info']);
        self::script('window.infoViewsMark = 1;');
        self::tap('#info-tab-inbox');
        self::waitFor(self::SHOWN, ['inbox']);

        self::tap('.tabbar a[href="/obrok19/"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/obrok19/"]\'); return location.pathname === "/obrok19/" && s !== null && !s.hidden;');
        self::assertTrue(self::script('return document.querySelector(\'[data-screen="/obrok19/informace"]\').hidden;'));

        self::tap('.tabbar a[href="/obrok19/informace"]');

        self::waitFor('return location.pathname === "/obrok19/informace/zpravy";');
        self::waitFor(self::SHOWN, ['inbox']);
        self::assertSame(1, self::script('return window.infoViewsMark;'), 'the screen was shown, not loaded');
    }

    public function testTheZpravyAddressPaintsTheZpravyPane(): void
    {
        self::pair('obrok19');
        self::visit('/obrok19/informace/zpravy');

        self::waitFor(self::SHOWN, ['inbox']);
        self::assertSame('/obrok19/informace/zpravy', self::script('return location.pathname;'));
        self::assertTrue(self::script('return document.querySelector("form[data-inbox-form]").getClientRects().length > 0;'));
        self::assertSame('0', self::script('return document.getElementById("info-tab-inbox").getAttribute("tabindex");'));
        self::assertSame('-1', self::script('return document.getElementById("info-tab-info").getAttribute("tabindex");'));
        self::assertSame('inbox', self::script('return ' . self::ROOT . '.dataset.view;'));
    }
}
