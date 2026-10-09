<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;

/**
 * Both views of the Program screen pick the day from a strip of chips. The timeline's
 * chip switches the page; the list's follows the scroll; and the current chip is
 * always scrolled into its strip, which only scrolls sideways.
 */
#[Group('browser')]
final class DayChipsTest extends BrowserTestCase
{
    /** Logged in as KORBO1, who has programmes on more than one day, on the Program screen. */
    private static function openProgramScreen(): void
    {
        self::visit('/korbo26/profil');
        self::waitFor('return document.readyState === "complete";');
        if (self::script('return document.querySelector(".appbar-who") === null;')) {
            self::$browser->findElement(WebDriverBy::cssSelector('input[name="tieCode"]'))->sendKeys('KORBO1');
            self::tap('[data-screen="/korbo26/profil"] button[type="submit"]');
            self::waitFor('const who = document.querySelector(".appbar-who"); return who !== null && who.textContent === "TIE KORBO1";');
        }
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
    }

    public function testTappingAChipSwitchesTheTimelinePage(): void
    {
        self::openProgramScreen();
        self::script('document.querySelectorAll(\'.day-chip[data-pg-kind="timeline"]\')[2].click();');
        self::waitFor('var c = document.querySelectorAll(\'.day-chip[data-pg-kind="timeline"]\')[2];'
            . ' var p = document.querySelectorAll(\'.tl-page\')[2];'
            . ' return c.getAttribute("aria-current") === "true" && p.classList.contains("is-active");');
        // exactly one chip is current, and nothing opened: no dialog, no history entry
        self::assertSame(1, self::script('return document.querySelectorAll(\'.day-chip[data-pg-kind="timeline"][aria-current="true"]\').length;'));
        self::assertFalse(self::script('return !!(history.state && history.state.pgOverlay);'));
        self::assertSame(0, self::script('return document.querySelectorAll("[inert]").length;'));
    }

    public function testScrollingTheListMovesTheCurrentChip(): void
    {
        self::openProgramScreen();
        self::script('document.querySelector(\'[data-pg-view="list"]\').click();');
        self::waitFor('return document.querySelector(\'[data-pg-root]\').dataset.view === "list";');
        self::assertGreaterThanOrEqual(2, self::script('return document.querySelectorAll(".pl-day").length;'));
        // the second day's heading 20px past the line the strip docks it under — the same
        // three tokens bandTop() adds up
        self::script(<<<'JS'
            const css = getComputedStyle(document.querySelector('[data-pg-root]'));
            const px = name => parseFloat(css.getPropertyValue(name)) || 0;
            const band = px('--appbar-height') + px('--appbar-inset') + px('--pager-height');
            const day = document.querySelectorAll('.pl-day')[1];
            window.scrollTo(0, window.scrollY + day.getBoundingClientRect().top - band + 20);
            JS);
        self::waitFor('var d = document.querySelectorAll(".pl-day")[1].dataset.key;'
            . ' return document.querySelector(\'.day-chip[data-pg-kind="list"][data-pg-page="\' + d + \'"]\').getAttribute("aria-current") === "true";');
        self::assertSame(1, self::script('return document.querySelectorAll(\'.day-chip[data-pg-kind="list"][aria-current="true"]\').length;'));
    }

    public function testTheCurrentChipIsScrolledIntoTheStrip(): void
    {
        self::overrideViewport(320, 700);
        try {
            self::openProgramScreen();
            self::assertTrue(self::script('var s = document.querySelector(\'[data-pg-days="timeline"]\'); return s.scrollWidth > s.clientWidth;'), 'the strip does not overflow at 320px');
            self::script('var c = document.querySelectorAll(\'.day-chip[data-pg-kind="timeline"]\'); c[c.length - 1].click();');
            self::waitFor('var s = document.querySelector(\'[data-pg-days="timeline"]\'), c = s.querySelector(\'[aria-current="true"]\');'
                . ' var a = s.getBoundingClientRect(), b = c.getBoundingClientRect(); return b.left >= a.left - 1 && b.right <= a.right + 1;');
            // only the strip moved: the page itself never scrolls sideways
            self::assertSame(0, self::script('return window.scrollX;'));
            self::assertSame(self::script('return document.documentElement.clientWidth;'), self::script('return document.documentElement.scrollWidth;'));
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
    }
}
