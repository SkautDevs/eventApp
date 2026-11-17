<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The homepage's next-programme card, against a clock pinned inside the korbo26 camp.
 * The fixture participant runs 31 (14:00–15:00) on 18 Sep and 45 (11:00) and 50 (16:00)
 * on the 19th: at 14:30 the first runs. The preregistered 36 (17:00 on the 18th) is not
 * among them: kissj lists real registrations only.
 */
#[Group('browser')]
final class NextUpTest extends BrowserTestCase
{
    private static function logIn(): void
    {
        // a fresh session: the test before this one may have left this one paired or pending
        self::visit('/korbo26/');
        self::$browser->getWebDriver()->manage()->deleteAllCookies();
        self::pair();
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

    /** The shown cards, each as "<key>: <lead>", in order. */
    private const string SHOWN_LEADS = 'return Array.from(document.querySelectorAll("[data-next-up]:not([hidden]) .next-card:not([hidden])")).map(function (c) { return c.dataset.key + ": " + c.querySelector("[data-next-lead]").textContent; });';

    public function testTheDeviceClockPicksTheCardsAndTheyFollowTheClock(): void
    {
        self::logIn();
        self::pinClock('2026-09-18T14:30:00+02:00');
        self::visit('/korbo26/');

        // the server, in October, hid the whole box; the clock in the camp shows the running
        // one and the next two
        self::waitFor('return (function () { ' . self::SHOWN . ' })() === "31";');
        self::assertSame('Můj program', self::script('return document.querySelector("[data-next-up] h2").textContent;'));
        self::assertSame(['31: Právě · do 15:00', '45: 11:00 · Nástěnka', '50: 16:00'], self::script(self::SHOWN_LEADS));

        // within the hour: how long until it starts
        self::script('window.pgClock = function () { return Date.parse("2026-09-19T10:30:00+02:00"); }; document.dispatchEvent(new Event("pg:tick"));');
        self::assertSame(['45: Za 30 min · 11:00 · Nástěnka', '50: 16:00'], self::script(self::SHOWN_LEADS));

        // past the last one, nothing is left to show
        self::script('window.pgClock = function () { return Date.parse("2026-09-19T17:00:00+02:00"); }; document.dispatchEvent(new Event("pg:tick"));');
        self::assertTrue(self::script('return document.querySelector("[data-next-up]").hidden;'));
    }

    /**
     * The countdown before an event counts Prague calendar days from the device clock. No
     * shipped event is reliably before its start, so the card is put on korbo26's screen
     * the way homepage.twig writes it.
     */
    public function testTheCountdownCountsPragueDaysAndGoesAtTheStart(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');
        $drawn = self::script(<<<'JS'
            const screen = document.querySelector('.screen:not([hidden])');
            screen.insertAdjacentHTML('beforeend', '<a class="link-card countdown" data-countdown data-start="2027-05-19" href="#"><h3>Potkáme se už za <span data-countdown-days>0</span> <span data-countdown-unit>dní</span></h3><p>19.–23. 5. 2027</p></a>');
            const card = screen.querySelector('[data-countdown]');
            const read = () => card.querySelector('[data-countdown-days]').textContent + ' ' + card.querySelector('[data-countdown-unit]').textContent + (card.hidden ? ' hidden' : '');
            const at = iso => { window.pgClock = () => Date.parse(iso); document.dispatchEvent(new Event('pg:tick')); return read(); };
            return [
                // 23:30 UTC on 7 Nov is already the 8th in Prague: 192 days to 19 May
                at('2026-11-07T23:30:00+00:00'),
                at('2027-05-17T12:00:00+02:00'),
                at('2027-05-18T00:00:00+02:00'),
                at('2027-05-19T00:00:00+02:00'),
            ];
            JS);

        self::assertSame(['192 dní', '2 dny', '1 den', '1 den hidden'], $drawn);
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
