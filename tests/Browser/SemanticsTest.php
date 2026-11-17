<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class SemanticsTest extends BrowserTestCase
{
    /**
     * Profil's mode control: three radios of one name, each named by its label; a choice
     * sets the mode and stores it, and "Podle systému" removes the stored key.
     */
    public function testTheModeControlIsThreeNamedRadiosAndSystemForgetsTheChoice(): void
    {
        self::pair();
        self::script('try { localStorage.removeItem("obrokColorMode"); } catch (e) {}');
        self::visit('/korbo26/profil');
        self::waitFor('return document.readyState === "complete" && document.querySelector("[data-mode-control]") !== null;');

        self::assertSame(
            [['mode', 'Světlý'], ['mode', 'Tmavý'], ['mode', 'Podle systému']],
            self::script('return Array.from(document.querySelectorAll("[data-mode-control] input")).map(i => [i.name, i.labels[0].textContent.trim()]);'),
        );
        self::assertSame('Vzhled', self::script('return document.querySelector("[data-mode-control] legend").textContent.trim();'));
        // nothing stored: the device's setting rules, and the control says so
        self::assertSame('system', self::script('return document.querySelector("[data-mode-control] input:checked").value;'));

        $state = 'let stored = null; try { stored = localStorage.getItem("obrokColorMode"); } catch (e) {} return [document.documentElement.getAttribute("data-mode"), stored, document.querySelector("[data-mode-control] input:checked").value];';
        self::tap('[data-mode-control] label:nth-of-type(2)');
        self::assertSame(['dark', 'dark', 'dark'], self::script($state));
        self::tap('[data-mode-control] label:nth-of-type(1)');
        self::assertSame(['light', 'light', 'light'], self::script($state));

        self::tap('[data-mode-control] label:nth-of-type(3)');
        $system = self::script('return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";');
        self::assertSame([$system, null, 'system'], self::script($state));

        // the stored choice is what a fresh page checks
        self::tap('[data-mode-control] label:nth-of-type(2)');
        self::visit('/korbo26/profil');
        self::waitFor('return document.readyState === "complete";');
        self::assertSame(['dark', 'dark', 'dark'], self::script($state));
        self::tap('[data-mode-control] label:nth-of-type(3)');
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
