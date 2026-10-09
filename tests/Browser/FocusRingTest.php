<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class FocusRingTest extends BrowserTestCase
{
    /** From the skip link through the app bar, every stop draws the one 2px ring. */
    public function testTabbingThroughTheAppBarShowsTheRing(): void
    {
        foreach (['/korbo26/', '/korbo26/programy'] as $path) {
            self::visit($path);
            self::waitFor('return document.readyState === "complete";');
            self::script('if (document.activeElement) { document.activeElement.blur(); } window.scrollTo(0, 0);');

            $seen = [];
            for ($i = 0; $i < 4; $i++) {
                self::$browser->getKeyboard()->sendKeys(WebDriverKeys::TAB);
                [$class, $style, $width] = self::script('const el = document.activeElement; const s = getComputedStyle(el); return [el.classList[0] || el.tagName, s.outlineStyle, s.outlineWidth];');
                self::assertSame('solid', $style, $path . ': ' . $class);
                self::assertSame('2px', $width, $path . ': ' . $class);
                $seen[] = $class;
            }
            self::assertSame(['skip-link', 'appbar-brand', 'appbar-mode', 'appbar-profile'], $seen, $path);
        }
    }

    /**
     * The sheet card takes focus when it opens; that is not a control and draws no ring.
     * It does match :focus-visible, or the test would pass with no exemption at all.
     */
    public function testADialogContainerDrawsNoRing(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        // keyboard first, so the programmatic focus that follows counts as focus-visible
        self::$browser->getKeyboard()->sendKeys(WebDriverKeys::TAB);
        self::script('const card = document.querySelector(".tl-page.is-active .tl-card"); card.focus(); card.click();');
        self::waitFor('return document.activeElement && document.activeElement.matches("[data-pg-sheet-card]");');

        self::assertSame(
            [true, 'none', 'none'],
            self::script('const el = document.activeElement; const s = getComputedStyle(el); return [el.matches(":focus-visible"), s.outlineStyle, s.boxShadow];'),
        );
    }

    /**
     * The sheet's close button runs to the edges of a card that clips, so its ring is
     * drawn inside the button's own box: offset inward, halo inset.
     */
    public function testTheSheetCloseRingSitsInsideItsBox(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::$browser->getKeyboard()->sendKeys(WebDriverKeys::TAB);
        self::script('const card = document.querySelector(".tl-page.is-active .tl-card"); card.focus(); card.click();');
        self::waitFor('return document.activeElement && document.activeElement.matches("[data-pg-sheet-card]");');
        self::script('document.querySelector(".sheet.is-open .sheet-close").focus();');

        [$visible, $style, $width, $offset, $shadow] = self::script('const el = document.activeElement; const s = getComputedStyle(el); return [el.matches(".sheet-close:focus-visible"), s.outlineStyle, s.outlineWidth, s.outlineOffset, s.boxShadow];');
        self::assertTrue($visible);
        self::assertSame('solid', $style);
        self::assertSame('2px', $width);
        self::assertSame('-2px', $offset);
        self::assertStringContainsString('inset', $shadow);
    }

    /** The day strip scrolls sideways and so clips; a chip's ring is drawn inside its own box. */
    public function testTheDayChipRingSitsInsideItsBox(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::$browser->getKeyboard()->sendKeys(WebDriverKeys::TAB);
        self::script('document.querySelector(\'.day-chip[data-pg-kind="timeline"]\').focus();');

        [$visible, $style, $width, $offset, $shadow] = self::script('const el = document.activeElement; const s = getComputedStyle(el); return [el.matches(".day-chip:focus-visible"), s.outlineStyle, s.outlineWidth, s.outlineOffset, s.boxShadow];');
        self::assertTrue($visible);
        self::assertSame('solid', $style);
        self::assertSame('2px', $width);
        self::assertSame('-2px', $offset);
        self::assertStringContainsString('inset', $shadow);
    }

    /**
     * After a keyboard-driven swap the loader focuses the new screen's section; it matches
     * :focus-visible (the link that opened it did) and still draws no ring.
     */
    public function testAScreenFocusedAfterAKeyboardSwapDrawsNoRing(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');
        self::$browser->getKeyboard()->sendKeys(WebDriverKeys::TAB);
        self::script('document.querySelector(\'.tabbar a[href$="/novinky"]\').focus();');
        self::$browser->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
        self::waitFor('const el = document.activeElement; return el && el.matches("main > section.screen[data-screen]") && !el.hidden && el.dataset.tab === "news";');

        self::assertSame(
            [true, 'none', 'none'],
            self::script('const el = document.activeElement; const s = getComputedStyle(el); return [el.matches(":focus-visible"), s.outlineStyle, s.boxShadow];'),
        );
    }
}
