<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class SemanticsTest extends BrowserTestCase
{
    public function testTheToggleKeepsItsNameAndFlipsItsState(): void
    {
        self::visit('/korbo26/');
        self::script('try { localStorage.removeItem("obrokColorMode"); } catch (e) {}');
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        $state = static fn (): array => self::script('const b = document.querySelector("[data-mode-toggle]"); return [b.getAttribute("aria-pressed"), b.getAttribute("aria-label"), document.documentElement.getAttribute("data-mode")];');
        self::assertSame(['false', 'Tmavý režim', 'light'], $state());

        self::tap('[data-mode-toggle]');
        self::assertSame(['true', 'Tmavý režim', 'dark'], $state());

        self::tap('[data-mode-toggle]');
        self::assertSame(['false', 'Tmavý režim', 'light'], $state());
    }

    /** Review Focus 1: the name follows the open programme, by tap and by deep link. */
    public function testTheSheetIsNamedByTheOpenProgramme(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');

        $named = 'const card = document.querySelector("[data-pg-sheet-card]"); const el = document.getElementById(card.getAttribute("aria-labelledby") || ""); return [card.hasAttribute("aria-label"), el ? el.closest("[data-pg-detail]").dataset.pgDetail : null, el ? el.getBoundingClientRect().height > 0 : false, el ? el.textContent.trim() !== "" : false];';

        $first = (string) self::script('const c = document.querySelector(".tl-page.is-active .tl-card"); c.click(); return c.dataset.pgOpen;');
        self::waitFor('return document.querySelector(".sheet.is-open") !== null;');
        self::assertSame([false, $first, true, true], self::script($named));

        self::$browser->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
        self::waitFor('return document.querySelector(".sheet.is-open") === null;');

        $last = (string) self::script('const all = document.querySelectorAll("[data-pg-detail]"); const id = all[all.length - 1].dataset.pgDetail; location.hash = "#section-1-program-" + id; return id;');
        self::assertNotSame($first, $last, 'the fixture needs two different programmes');
        self::waitFor('return document.querySelector(".sheet.is-open") !== null;');
        self::assertSame([false, $last, true, true], self::script($named));
    }
}
