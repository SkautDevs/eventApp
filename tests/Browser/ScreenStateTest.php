<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;

/**
 * Screens are kept, not re-rendered: leaving one and coming back finds it exactly as it
 * was. A full navigation would lose the window marker set here, so every assertion also
 * proves the loader did the switch.
 */
#[Group('browser')]
final class ScreenStateTest extends BrowserTestCase
{
    public function testATypedTieCodeSurvivesALeaveAndAReturn(): void
    {
        self::visit('/korbo26/profil');
        self::script('window.__noReload = 1;');
        self::$browser->findElement(WebDriverBy::cssSelector('[data-screen="/korbo26/profil"] input[name="tieCode"]'))->sendKeys('KOR');

        self::tap('.tabbar a[href="/korbo26/programy"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); return s && !s.hidden;');
        self::tap('.appbar-profile');
        self::waitFor('return !document.querySelector(\'[data-screen="/korbo26/profil"]\').hidden;');

        self::assertSame('KOR', self::script('return document.querySelector(\'[data-screen="/korbo26/profil"] input[name="tieCode"]\').value;'));
        self::assertSame(1, self::script('return window.__noReload;'));
    }

    /** The warm-up fetches every other screen up front, so a first tap needs no network. */
    public function testEveryScreenIsFetchedBeforeItsFirstTap(): void
    {
        self::visit('/korbo26/');
        self::waitFor(<<<'JS'
            return Array.from(document.querySelectorAll('.tabbar .tab, .appbar-profile'))
                .every(link => document.querySelector('[data-screen="' + new URL(link.href).pathname + '"]') !== null);
            JS, [], 15);
        // the icon fonts are read in too, though no brand icon is on the homepage
        self::waitFor('return Array.from(document.fonts).filter(f => f.family.includes("Font Awesome")).every(f => f.status === "loaded");');
        // from here on a screen fetch would fail: the tap must be served from the warm copy
        self::script('window.__noReload = 1; window.fetch = () => Promise.reject(new TypeError("offline"));');

        self::tap('.tabbar a[href="/korbo26/novinky"]');

        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/novinky"]\'); return s && !s.hidden;');
        self::assertSame(1, self::script('return window.__noReload;'));
        self::assertSame(1, self::script('return document.querySelectorAll(\'[data-screen="/korbo26/novinky"]\').length;'));
    }

    /** The tab you are on scrolls its screen to the top instead of reloading the app. */
    public function testTheCurrentTabScrollsToTheTopAndReloadsNothing(): void
    {
        self::visit('/korbo26/novinky');
        // tall enough to scroll whatever the news holds
        self::script('const pad = document.createElement("div"); pad.style.height = "3000px"; document.querySelector(\'[data-screen="/korbo26/novinky"]\').append(pad); window.__noReload = 1; window.scrollTo(0, 400);');
        self::waitFor('return window.scrollY > 0;');

        self::tap('.tabbar a[href="/korbo26/novinky"]');

        self::waitFor('return window.scrollY === 0;');
        self::assertSame(1, self::script('return window.__noReload;'));
    }

    /** korbo26 has no map; obrok19 is the event whose map is published. */
    public function testTheMapKeepsItsFrame(): void
    {
        self::visit('/obrok19/mapa');
        self::script('window.__noReload = 1; window.__frame = document.querySelector(\'[data-screen="/obrok19/mapa"] iframe\');');
        self::assertTrue(self::script('return window.__frame instanceof HTMLIFrameElement;'));

        self::tap('.tabbar a[href="/obrok19/programy"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/obrok19/programy"]\'); return s && !s.hidden;');
        self::tap('.tabbar a[href="/obrok19/mapa"]');
        self::waitFor('return !document.querySelector(\'[data-screen="/obrok19/mapa"]\').hidden;');

        self::assertTrue(self::script('return document.querySelector(\'[data-screen="/obrok19/mapa"] iframe\') === window.__frame && window.__frame.isConnected;'));
        self::assertSame(1, self::script('return window.__noReload;'));
    }

    /**
     * A screen switch snapshots the tab bar, and the snapshot is rounded to whole device
     * pixels: a bar centred by translateX(-50%) sits on a half pixel at 375px on a 3x
     * phone, so it jumped sideways for the switch and back after it.
     */
    public function testTheTabBarIsPlacedWithoutATransform(): void
    {
        self::visit('/obrok27/');

        self::assertSame('none', self::script('return getComputedStyle(document.querySelector(".tabbar")).transform;'));
    }

    /** Sideways the home screen scrolls rather than squeezing its mark away. */
    public function testASidewaysPhoneKeepsAReadableHomeMark(): void
    {
        self::overrideViewport(740, 400);
        try {
            foreach (['obrok27', 'obrok19'] as $slug) {
                self::visit('/' . $slug . '/');
                self::waitFor('return Array.from(document.querySelectorAll(\'[data-screen="/' . $slug . '/"] img.mainLogo\')).some(img => img.complete);');
                $mark = self::script('return Array.from(document.querySelectorAll(\'[data-screen="/' . $slug . '/"] img.mainLogo\')).map(img => img.getBoundingClientRect().height).find(h => h > 0) || 0;');

                self::assertGreaterThanOrEqual(96, $mark, $slug);
            }
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
    }

    /**
     * A phone widens its layout viewport to fit anything that sticks out sideways, which
     * aborts the screen switch and drops the fixed tab bar below the fold. The timeline's
     * day page slides in from 14px to the right every time the Program screen is shown.
     */
    public function testShowingTheProgramScreenNeverWidensThePage(): void
    {
        self::devTools()->execute('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']]]);
        try {
            self::visit('/obrok27/');
            self::waitFor('return document.querySelector(\'[data-screen="/obrok27/programy"]\') !== null;', [], 15);
            self::script(<<<'JS'
                window.__widest = 0;
                const original = document.startViewTransition.bind(document);
                const sample = frames => {
                    window.__widest = Math.max(window.__widest, document.documentElement.scrollWidth - document.documentElement.clientWidth);
                    if (frames > 0) {
                        requestAnimationFrame(() => sample(frames - 1));
                    } else {
                        window.__sampled = true;
                    }
                };
                document.startViewTransition = update => {
                    const transition = original(update);
                    transition.updateCallbackDone.then(() => sample(10));

                    return transition;
                };
                JS);

            self::tap('.tabbar a[href="/obrok27/programy"]');
            self::waitFor('return window.__sampled === true;');

            self::assertSame(0, self::script('return window.__widest;'));
        } finally {
            self::devTools()->execute('Emulation.setEmulatedMedia', ['features' => []]);
        }
    }
}
