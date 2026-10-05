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
}
