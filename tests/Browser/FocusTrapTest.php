<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\Group;

/** An open programme sheet is a real dialog: Tab cannot leave it, Escape gives focus back. */
#[Group('browser')]
final class FocusTrapTest extends BrowserTestCase
{
    public function testTabStaysInTheSheetAndEscapeReturnsToTheOpener(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        // a script click, not a WebDriver one: the sticky pager can cover a card the
        // driver scrolls to, and the point here is the dialog, not the hit testing
        self::script('const card = document.querySelector(".tl-page.is-active .tl-card"); card.setAttribute("data-test-opener", ""); card.focus(); card.click();');
        self::waitFor('return document.querySelector(".sheet.is-open") !== null;');

        for ($i = 0; $i < 20; $i++) {
            self::$browser->getKeyboard()->sendKeys(WebDriverKeys::TAB);
            self::assertTrue(
                self::script('return document.activeElement !== null && document.activeElement.closest(".sheet-card") !== null;'),
                'Tab ' . ($i + 1) . ' left the sheet',
            );
        }

        self::$browser->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');
        self::assertTrue(self::script('return document.activeElement.hasAttribute("data-test-opener");'));
    }
}
