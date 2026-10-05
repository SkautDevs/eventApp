<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class TouchTargetTest extends BrowserTestCase
{
    private const SMALL = <<<'JS'
        const small = [];
        arguments[0].forEach(selector => document.querySelectorAll(selector).forEach(el => {
            if (!el.getClientRects().length) { return; }
            const r = el.getBoundingClientRect();
            if (r.width < 44 || r.height < 44) { small.push(selector + ' ' + Math.round(r.width) + 'x' + Math.round(r.height)); }
        }));
        return small;
        JS;

    /** The title's box against every visible control on the right of the bar, and the page's width. */
    private const BAR = <<<'JS'
        const title = document.querySelector('.appbar-title').getBoundingClientRect();
        const overlaps = [];
        document.querySelectorAll('.appbar-tools .appbar-mode, .appbar-tools .appbar-profile').forEach(el => {
            if (!el.getClientRects().length) { return; }
            const r = el.getBoundingClientRect();
            if (title.right > r.left + 0.5 && title.left < r.right - 0.5) {
                overlaps.push(el.className + ' ' + Math.round(r.left) + '..' + Math.round(r.right) + ' under title ' + Math.round(title.left) + '..' + Math.round(title.right));
            }
        });
        const tools = document.querySelector('.appbar-tools').getBoundingClientRect();
        return {
            width: window.innerWidth,
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            titleRight: title.right,
            titleWidth: title.width,
            toolsLeft: tools.left,
            overlaps: overlaps,
            mode: document.querySelector('.appbar-mode') !== null,
        };
        JS;

    public function testEveryControlIsAtLeast44By44(): void
    {
        self::visit('/korbo26/profil');
        self::waitFor('return document.readyState === "complete";');
        self::assertSame([], self::script(self::SMALL, [['.appbar-mode', '.appbar-profile', '.appbar-brand', '.tab', 'input[type=text]', '.btn']]));

        // logged in, so the personal list has a strip of its own to measure
        self::$browser->findElement(WebDriverBy::cssSelector('input[name="tieCode"]'))->sendKeys('KORBO1');
        self::tap('form.stacked-form button[type="submit"]');
        self::waitFor('return document.querySelector(".appbar-who") !== null;');

        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::assertSame([], self::script(self::SMALL, [['.appbar-mode', '.appbar-profile', '.appbar-brand', '.tab', '.pager-arrow', '.pager-zoom-btn', '.pager-menu-item', '.pager-menu-close']]));

        self::script('document.querySelector(\'[data-pg-view="list"]\').click();');
        self::waitFor('return document.querySelector(\'[data-pg-root]\').dataset.view === "list";');
        self::assertSame([], self::script(self::SMALL, [['.pager-arrow']]));
    }

    /** Review Focus 2: the minimums must still fit the narrowest phone, logged in, with the toggle. */
    public function testTheNarrowestPhoneStillFitsTheBars(): void
    {
        self::overrideViewport(320, 700);
        try {
            self::visit('/korbo26/programy');
            self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
            self::assertSame(320, self::script('return window.innerWidth;'), 'the viewport override did not take');

            $bar = self::script(self::BAR);
            self::assertLessThanOrEqual($bar['toolsLeft'] + 0.5, $bar['titleRight'], 'the title runs under the tools');
            self::assertGreaterThan(30, $bar['titleWidth'], 'the title was squeezed out');
            self::assertSame([], $bar['overlaps']);
            self::assertSame($bar['clientWidth'], $bar['scrollWidth'], 'the page scrolls sideways');
            self::assertGreaterThanOrEqual(143, self::script('return document.querySelector(\'[data-pg-pager="timeline"] .pager-label\').getBoundingClientRect().width;'));
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
    }

    /**
     * The identity's name gives way before the screen's name, entirely: at an ordinary
     * phone width the longest shipped title must not lose even a sub-pixel to it.
     * navigamus25's homepage, logged in, is that case.
     */
    public function testTheTitleKeepsItsFullWidthOnAnOrdinaryPhone(): void
    {
        self::visit('/navigamus25/profil');
        self::waitFor('return document.readyState === "complete";');
        if (self::script('return document.querySelector(".appbar-who") === null;')) {
            self::$browser->findElement(WebDriverBy::cssSelector('input[name="tieCode"]'))->sendKeys('NAVIGAMUS1');
            self::tap('form.stacked-form button[type="submit"]');
            self::waitFor('return document.querySelector(".appbar-who") !== null;');
        }

        foreach ([320, 360] as $width) {
            self::overrideViewport($width, 700);
            try {
                self::visit('/navigamus25/');
                self::waitFor('return document.readyState === "complete" && document.querySelector(".appbar-who") !== null;');
                self::assertSame($width, self::script('return window.innerWidth;'), 'the viewport override did not take');
                // scrollWidth is a whole number and the squeeze can be a fraction of a pixel,
                // which is all an ellipsis needs; the text's own laid-out width is exact
                [$box, $natural, $text] = self::script('const t = document.querySelector(".appbar-title"); const r = document.createRange(); r.selectNodeContents(t); return [t.getBoundingClientRect().width, r.getBoundingClientRect().width, t.textContent];');
                self::assertGreaterThanOrEqual($natural - 0.001, $box, sprintf('"%s" is truncated at %dpx (%.3f of %.3f)', $text, $width, $box, $natural));
            } finally {
                self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
            }
        }
    }

    /**
     * 200% page zoom on a 390px phone is a 195px CSS viewport. korbo26 declares a dark
     * set, so its bar carries both the mode toggle and the profile link, the widest the
     * right-hand end gets.
     */
    public function testTwoHundredPercentZoomKeepsTheBarApart(): void
    {
        self::overrideViewport(195, 422);
        try {
            self::visit('/korbo26/programy');
            self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
            self::assertSame(195, self::script('return window.innerWidth;'), 'the viewport override did not take');

            $bar = self::script(self::BAR);
            self::assertTrue($bar['mode'], 'korbo26 should carry the mode toggle');
            self::assertSame($bar['clientWidth'], $bar['scrollWidth'], 'the page scrolls sideways');
            self::assertSame([], $bar['overlaps'], 'the title overlaps the bar\'s controls');
            // the 4ch floor is 44px at 20px; anything under 40 is the ellipsis alone
            self::assertGreaterThanOrEqual(40, $bar['titleWidth'], 'the title was squeezed out');
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
    }
}
