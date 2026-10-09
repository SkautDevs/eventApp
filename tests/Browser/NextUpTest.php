<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
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

    public function testTheCardOpensThatProgrammeInMyProgramWithoutReloading(): void
    {
        self::logIn();
        self::pinClock('2026-09-18T14:30:00+02:00');
        self::visit('/korbo26/');
        self::waitFor('return (function () { ' . self::SHOWN . ' })() === "31";');
        self::script('window.__noReload = 1;');

        self::tap('.next-card:not([hidden])');

        self::waitFor('return document.querySelector(".sheet.is-open") !== null;');
        self::assertSame(1, self::script('return window.__noReload;'));
        self::assertSame('/korbo26/programy#muj-program-31', self::script('return location.pathname + location.hash;'));
        self::assertSame('list', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
        self::assertTrue(self::script('return document.querySelector(\'[data-pg-detail="31"]\').classList.contains("is-open");'));
    }

    public function testTheCardWorksOnAProgramScreenAlreadyLoaded(): void
    {
        self::logIn();
        self::pinClock('2026-09-18T14:30:00+02:00');
        self::visit('/korbo26/');
        self::tap('.tabbar a[href="/korbo26/programy"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); return s && !s.hidden;');
        self::tap('.tabbar a[href="/korbo26/"]');
        self::waitFor('return (function () { ' . self::SHOWN . ' })() === "31";');
        self::script('window.__noReload = 1;');

        self::tap('.next-card:not([hidden])');

        self::waitFor('return document.querySelector(".sheet.is-open") !== null;');
        self::assertSame(1, self::script('return window.__noReload;'));
        self::assertSame('list', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
        self::assertTrue(self::script('return document.querySelector(\'[data-pg-detail="31"]\').classList.contains("is-open");'));
    }
}
