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
}
