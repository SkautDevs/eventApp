<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * Round B carry-over: a background revalidation that finds the same screen still updates
 * its data-fetched-at — and touches nothing else.
 */
#[Group('browser')]
final class RevalidationTest extends BrowserTestCase
{
    public function testABackgroundRevalidationUpdatesTheTimestampWhenNothingElseChanged(): void
    {
        self::visit('/korbo26/');
        self::tap('.tabbar a[href="/korbo26/programy"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); return s && !s.hidden && s.querySelector(".tl-card") !== null;');
        $before = self::script(<<<'JS'
            const section = document.querySelector('[data-screen="/korbo26/programy"]');
            window.__card = section.querySelector('.tl-card');
            return section.getAttribute('data-fetched-at');
            JS);
        // the attribute has a resolution of one second
        usleep(1_100_000);

        // what the worker posts when the server's copy of a screen differs from its own
        self::script("navigator.serviceWorker.dispatchEvent(new MessageEvent('message', {data: {type: 'screen-updated', path: '/korbo26/programy'}}));");

        self::waitFor('return document.querySelector(\'[data-screen="/korbo26/programy"]\').getAttribute("data-fetched-at") !== arguments[0];', [$before]);
        self::assertTrue(self::script('return window.__card.isConnected;'), 'an unchanged screen is not morphed');
    }

    /** The loader keeps the landing screen's markup to compare later copies against; a module that drew into that screen first would make every landing morph once. */
    public function testALandingScreenThatDidNotChangeIsNotMorphed(): void
    {
        // a device clock inside the camp, so Domů's scripts do draw into the landing screen:
        // its date heading names that day, not the one the server rendered it for
        self::pinClock('2026-09-18T14:30:00+02:00');
        self::visit('/korbo26/');
        // the teeth of this test: a script did draw into the landing screen, so a loader that
        // filed the screen after the draw would see a change. The server renders the heading
        // from its own real clock (HomeModel::todayLabel()), so on a real Friday 18 September
        // the two would agree; this then goes red rather than prove nothing.
        self::waitFor('const h = document.querySelector("main > [data-screen]:not([hidden]) [data-home-date]"); return h && h.textContent === "Pátek 18. 9.";');
        $served = (string) file_get_contents(self::baseUri() . '/korbo26/');
        self::assertSame(1, preg_match('~<h2 class="home-date" data-home-date>([^<]*)</h2>~u', $served, $heading));
        self::assertNotSame('Pátek 18. 9.', $heading[1], 'the server rendered the day the device clock names');
        self::script('window.__morphs = 0; document.addEventListener("screen:morphed", function () { window.__morphs++; });');
        $before = self::script('return document.querySelector("main > [data-screen]:not([hidden])").getAttribute("data-fetched-at");');
        usleep(1_100_000);
        // tell the loader the worker has a newer copy, the way this class's other cases do
        $this->announceScreenUpdated('/korbo26/');
        self::waitFor('return document.querySelector("main > [data-screen]:not([hidden])").getAttribute("data-fetched-at") !== arguments[0];', [$before]);
        self::assertSame(0, self::script('return window.__morphs;'));
    }

    /** What the worker posts when the server's copy of a screen differs from its own. */
    private function announceScreenUpdated(string $path): void
    {
        self::script("navigator.serviceWorker.dispatchEvent(new MessageEvent('message', {data: {type: 'screen-updated', path: arguments[0]}}));", [$path]);
    }
}
