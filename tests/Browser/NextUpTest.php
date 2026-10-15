<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The homepage's next-programme card, against a clock pinned inside the korbo26 camp.
 * KORBO1 runs 31 (14:00–15:00) and 36 (17:00–19:00) on 18 Sep: at 14:30 the first runs.
 */
#[Group('browser')]
final class NextUpTest extends BrowserTestCase
{
    private static function logIn(): void
    {
        // there is no logout: the test before this one may have left the session logged in
        self::visit('/korbo26/');
        self::$browser->getWebDriver()->manage()->deleteAllCookies();
        self::visit('/korbo26/profil');
        self::$browser->findElement(WebDriverBy::cssSelector('input[name="tieCode"]'))->sendKeys('KORBO1');
        self::tap('[data-screen="/korbo26/profil"] button[type="submit"]');
        self::waitFor('const who = document.querySelector(".appbar-who"); return who !== null && who.textContent === "KORBO1";');
    }

    /** @return array<string, array{bool}> */
    public static function motion(): array
    {
        // the test Chrome forces reduced motion, where a screen swaps at once; with motion
        // the swap waits for the view transition, and a phone runs with motion
        return ['reduced motion' => [false], 'motion' => [true]];
    }

    private static function setMotion(bool $on): void
    {
        self::devTools()->execute('Emulation.setEmulatedMedia', ['features' => $on ? [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']] : []]);
    }

    private static function assertLandedOn31(): void
    {
        self::waitFor('return document.querySelector(".sheet.is-open") !== null;');
        self::assertSame(1, self::script('return window.__noReload;'));
        self::assertSame('/korbo26/programy#muj-program-31', self::script('return location.pathname + location.hash;'));
        self::assertSame('list', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
        self::assertTrue(self::script('return document.querySelector(\'[data-pg-detail="31"]\').classList.contains("is-open");'));
        // a dialog that opens holds the focus, or a screen reader keeps reading behind it
        self::assertTrue(self::script('return document.querySelector(".sheet.is-open").contains(document.activeElement);'));
        self::waitFor('const r = document.querySelector(\'.view-list .pl-item[data-key="31"]\').getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight;');
    }

    private const string SHOWN = 'var c = document.querySelector("[data-next-up]:not([hidden]) .next-card:not([hidden])"); return c ? c.dataset.key : null;';

    public function testTheDeviceClockPicksTheCardAndItFollowsTheClock(): void
    {
        self::logIn();
        self::pinClock('2026-09-18T14:30:00+02:00');
        self::visit('/korbo26/');

        // the server, in October, hid the whole box; the clock in the camp shows the running one
        self::waitFor('return (function () { ' . self::SHOWN . ' })() === "31";');
        self::assertSame('Teď probíhá', self::script('return document.querySelector(".next-card:not([hidden]) [data-next-kicker]").textContent;'));

        self::script('window.pgClock = function () { return Date.parse("2026-09-18T15:30:00+02:00"); }; document.dispatchEvent(new Event("pg:tick"));');
        self::assertSame('36', self::script(self::SHOWN));
        self::assertSame('Tvůj další program', self::script('return document.querySelector(".next-card:not([hidden]) [data-next-kicker]").textContent;'));
    }

    #[DataProvider('motion')]
    public function testTheCardOpensThatProgrammeInMyProgramWithoutReloading(bool $motion): void
    {
        self::logIn();
        self::pinClock('2026-09-18T14:30:00+02:00');
        self::setMotion($motion);
        try {
            self::visit('/korbo26/');
            self::waitFor('return (function () { ' . self::SHOWN . ' })() === "31";');
            self::script('window.__noReload = 1;');

            self::tap('.next-card:not([hidden])');

            self::assertLandedOn31();
        } finally {
            self::setMotion(false);
        }
    }

    #[DataProvider('motion')]
    public function testTheCardWorksOnAProgramScreenAlreadyLoaded(bool $motion): void
    {
        self::logIn();
        self::pinClock('2026-09-18T14:30:00+02:00');
        self::setMotion($motion);
        try {
            self::visit('/korbo26/');
            self::tap('.tabbar a[href="/korbo26/programy"]');
            self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); return s && !s.hidden;');
            self::tap('.tabbar a[href="/korbo26/"]');
            self::waitFor('return (function () { ' . self::SHOWN . ' })() === "31";');
            self::script('window.__noReload = 1;');

            self::tap('.next-card:not([hidden])');

            self::assertLandedOn31();
        } finally {
            self::setMotion(false);
        }
    }
}
