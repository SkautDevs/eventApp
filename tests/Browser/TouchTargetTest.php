<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class TouchTargetTest extends BrowserTestCase
{
    /** obrok19's admin link for this class, so the admin page's inbox controls can be measured. */
    private const string ADMIN_TOKEN = 'touch-target-admin-token';

    private const string SMALL = <<<'JS'
        const small = [];
        arguments[0].forEach(selector => document.querySelectorAll(selector).forEach(el => {
            if (!el.getClientRects().length) { return; }
            const r = el.getBoundingClientRect();
            if (r.width < 44 || r.height < 44) { small.push(selector + ' ' + Math.round(r.width) + 'x' + Math.round(r.height)); }
        }));
        return small;
        JS;

    /** The title's box against the bar's inner right edge (its content box), and the page's width. */
    private const string BAR = <<<'JS'
        const bar = document.querySelector('.appbar');
        const title = document.querySelector('.appbar-title').getBoundingClientRect();
        const inner = bar.getBoundingClientRect().right - parseFloat(getComputedStyle(bar).paddingRight);
        return {
            width: window.innerWidth,
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            titleRight: title.right,
            titleWidth: title.width,
            barInner: inner,
        };
        JS;

    public function testEveryControlIsAtLeast44By44(): void
    {
        // logged out first, whatever ran before: the start button is what is measured
        self::visit('/korbo26/');
        self::$browser->manage()->deleteAllCookies();
        self::visit('/korbo26/profil');
        self::waitFor('return document.readyState === "complete";');
        self::assertSame([], self::script(self::SMALL, [['.appbar-brand', '.tab', '.btn']]));

        // logged out, a programme's sheet offers the way to log in: a full-width button, not a bare inline link
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::script('document.querySelector(".tl-card").click();');
        self::waitFor('return document.querySelector(".sheet.is-open .sheet-body.is-open .sheet-footer a") !== null;');
        self::assertTrue(self::script('return document.querySelector(".sheet-body.is-open .sheet-footer a").getClientRects().length > 0;'));
        self::assertSame([], self::script(self::SMALL, [['.sheet-body.is-open .sheet-footer a']]));

        // the sheet's pin to Místa, on the shortest place name obrok27 has (Les, programme 53)
        self::visit('/obrok27/programy/prehled#section-4-program-53');
        self::waitFor('return document.querySelector(".sheet.is-open .sheet-body.is-open .place-pin") !== null;');
        self::assertSame('Les', self::script('return document.querySelector(".sheet-body.is-open .place-pin").textContent.trim();'));
        self::assertSame([], self::script(self::SMALL, [['.sheet-body.is-open .place-pin']]));

        // paired, so the personal list has a strip of its own to measure, and Profil its mode control
        self::pair();
        self::visit('/korbo26/profil');
        self::waitFor('return document.readyState === "complete";');
        self::assertSame([], self::script(self::SMALL, [['.tab', '.btn', '.mode-control label']]));

        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::assertSame([], self::script(self::SMALL, [['.appbar-brand', '.tab', '.day-chip', '.pager-zoom-btn']]));

        self::script('document.querySelector(\'[data-pg-view="list"]\').click();');
        self::waitFor('return document.querySelector(\'[data-pg-root]\').dataset.view === "list";');
        self::assertSame([], self::script(self::SMALL, [['.day-chip']]));
    }

    /**
     * Napiš organizátorům on obrok19: Informace's two view pills, the Zprávy view's two
     * kinds (the whole label is the target), its button, and on the admin page a message's
     * `Vyřízeno` and the `Smazat…` summary that reveals the delete. The message is sent
     * through the form first. Then obrok27's Zprávy view, for the call button of its crisis
     * contact.
     */
    public function testTheViewPillsTheMessageFormAndTheAdminInboxControlsAreAtLeast44By44(): void
    {
        self::pair('obrok19');
        self::visit('/obrok19/informace');
        self::waitFor('const r = document.querySelector("[data-info-root]"); return r !== null && r.dataset.infoReady === "1";');
        self::assertSame(2, self::script('return document.querySelectorAll(".infoview .tabs-tab").length;'));
        self::assertSame([], self::script(self::SMALL, [['.infoview .tabs-tab', '.tab']]));

        self::tap('#info-tab-inbox');
        self::waitFor('return location.pathname === "/obrok19/informace/zpravy" && document.querySelector("form[data-inbox-form]").getClientRects().length > 0;');
        self::assertSame([], self::script(self::SMALL, [['.infoview .tabs-tab', '.inbox-kind', 'form[data-inbox-form] .btn', '.tab']]));

        self::tap('input[name="kind"][value="help"]');
        self::$browser->findElement(WebDriverBy::cssSelector('#inbox-body'))->sendKeys('Změřit tlačítka');
        self::tap('form[data-inbox-form] button[type="submit"]');
        self::waitFor('return location.pathname === "/obrok19/informace/zpravy" && document.querySelector("[data-toast]") !== null;');

        self::visit('/obrok19/admin/notify?token=' . self::ADMIN_TOKEN);
        self::waitFor('return document.readyState === "complete" && document.querySelector("#inbox .inbox-message") !== null;');
        self::assertSame([], self::script(self::SMALL, [['#inbox .inbox-message .btn', '#inbox .inbox-delete > summary']]));

        // obrok19 has no crisis contact; obrok27's Zprávy view puts its Zavolat button above the form
        self::pair('obrok27');
        self::visit('/obrok27/informace/zpravy');
        self::waitFor('return document.readyState === "complete" && document.querySelector(".inbox-urgent .emergency-call") !== null && document.querySelector(".inbox-urgent .emergency-call").getClientRects().length > 0;');
        self::assertSame([], self::script(self::SMALL, [['.inbox-urgent .emergency-call', '.infoview .tabs-tab']]));
    }

    /** @return array<string, string> */
    protected static function serverEnvironment(): array
    {
        return ['ADMIN_TOKEN_OBROK19' => self::ADMIN_TOKEN] + parent::serverEnvironment();
    }

    /**
     * Review Focus 2: the minimums must still fit the narrowest phone. The right end of
     * the bar is empty, so the title only has to stay inside the bar's own padding.
     */
    public function testTheNarrowestPhoneStillFitsTheBars(): void
    {
        self::overrideViewport(320, 700);
        try {
            self::visit('/korbo26/programy');
            self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
            self::assertSame(320, self::script('return window.innerWidth;'), 'the viewport override did not take');

            $bar = self::script(self::BAR);
            self::assertLessThanOrEqual($bar['barInner'] + 0.5, $bar['titleRight'], 'the title runs past the bar\'s padding');
            self::assertGreaterThan(30, $bar['titleWidth'], 'the title was squeezed out');
            self::assertSame($bar['clientWidth'], $bar['scrollWidth'], 'the page scrolls sideways');
            // the strip keeps room for at least two whole chips beside the zoom pair, and scrolls the rest
            self::assertGreaterThanOrEqual(143, self::script('return document.querySelector(\'[data-pg-days="timeline"]\').getBoundingClientRect().width;'));
            self::assertSame([], self::script(self::SMALL, [['.day-chip']]));
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
    }

    /**
     * The bar is the screen's name: at an ordinary phone width a paired event's title
     * must not lose even a sub-pixel.
     *
     * The widths are what they are because the hermetic lane now loads the real fonts
     * (self-hosted since Round E); it used to measure the fallback. navigamus27's
     * "NAVIGAMUS 2027" in Seatren is about 171px, and at 320px it does not fit beside the
     * wide N27 mark, so it ellipsises there — as the skautbold title before it always did.
     * That is accepted (controller ruling E-1), so navigamus27 is held at 360px only. obrok27, in
     * Montserrat, is held at both 320px and 360px.
     */
    public function testTheTitleKeepsItsFullWidthOnAnOrdinaryPhone(): void
    {
        foreach (['navigamus27' => [360], 'obrok27' => [320, 360]] as $slug => $widths) {
            self::pair($slug);

            foreach ($widths as $width) {
                self::overrideViewport($width, 700);
                try {
                    self::visit('/' . $slug . '/');
                    self::waitFor('return document.readyState === "complete" && document.querySelector(\'meta[name="push-identity"]\').content !== "";');
                    // measured with the real face in place, not the fallback it swaps from
                    self::asyncScript('const done = arguments[arguments.length - 1]; document.fonts.ready.then(() => done(true));');
                    self::assertSame($width, self::script('return window.innerWidth;'), 'the viewport override did not take');
                    // scrollWidth is a whole number and the squeeze can be a fraction of a pixel,
                    // which is all an ellipsis needs; the text's own laid-out width is exact
                    [$box, $natural, $text] = self::script('const t = document.querySelector(".appbar-title"); const r = document.createRange(); r.selectNodeContents(t); return [t.getBoundingClientRect().width, r.getBoundingClientRect().width, t.textContent];');
                    self::assertGreaterThanOrEqual($natural - 0.001, $box, sprintf('%s: "%s" is truncated at %dpx (%.3f of %.3f)', $slug, $text, $width, $box, $natural));
                } finally {
                    self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
                }
            }
        }
    }

    /** Spec, Milestone 3 "Measured": five tabs fit at 320px on every event, labels whole, targets 44 × 44. */
    public function testFiveTabsFitTheNarrowestPhone(): void
    {
        self::overrideViewport(320, 700);
        try {
            foreach (['obrok27', 'obrok19', 'navigamus27'] as $slug) {
                self::visit('/' . $slug . '/');
                self::waitFor('return document.readyState === "complete";');
                // measured with the real face in place, not the fallback it swaps from
                self::asyncScript('const done = arguments[arguments.length - 1]; document.fonts.ready.then(() => done(true));');
                self::assertSame(320, self::script('return window.innerWidth;'), 'the viewport override did not take');

                $tabs = self::script(<<<'JS'
                    return Array.from(document.querySelectorAll('.tabbar .tab')).map(tab => {
                        const r = tab.getBoundingClientRect();
                        const label = tab.querySelector('span:last-child');
                        return {width: r.width, height: r.height, cut: label.scrollWidth > label.clientWidth, href: tab.getAttribute('href'), label: label.textContent};
                    });
                    JS);
                self::assertIsArray($tabs);
                self::assertCount(5, $tabs, $slug);
                foreach ($tabs as $tab) {
                    self::assertGreaterThanOrEqual(44, $tab['width'], $slug . ' ' . $tab['label']);
                    self::assertGreaterThanOrEqual(44, $tab['height'], $slug . ' ' . $tab['label']);
                    self::assertFalse($tab['cut'], $slug . ': "' . $tab['label'] . '" is cut');
                }
                self::assertSame('/' . $slug . '/profil', end($tabs)['href'], $slug);
                self::assertTrue(self::script('return document.documentElement.scrollWidth === document.documentElement.clientWidth;'), $slug . ': the page scrolls sideways');
            }
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
    }

    /**
     * 200% page zoom on a 390px phone is a 195px CSS viewport. The right end of the bar
     * is empty (Profil is a tab), so the title only has to stay inside the bar's padding.
     */
    public function testTwoHundredPercentZoomKeepsTheBarApart(): void
    {
        self::overrideViewport(195, 422);
        try {
            self::visit('/korbo26/programy');
            self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
            self::assertSame(195, self::script('return window.innerWidth;'), 'the viewport override did not take');

            $bar = self::script(self::BAR);
            self::assertSame($bar['clientWidth'], $bar['scrollWidth'], 'the page scrolls sideways');
            self::assertLessThanOrEqual($bar['barInner'] + 0.5, $bar['titleRight'], 'the title runs past the bar\'s padding');
            // the 4ch floor is 44px at 20px; anything under 40 is the ellipsis alone
            self::assertGreaterThanOrEqual(40, $bar['titleWidth'], 'the title was squeezed out');
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
    }
}
