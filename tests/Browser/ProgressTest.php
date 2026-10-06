<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class ProgressTest extends BrowserTestCase
{
    public function testTheBarShowsWhileAFirstVisitLoadsAndGoesWhenTheScreenIsShown(): void
    {
        self::visit('/korbo26/');
        // slow every screen fetch down enough to be seen; the loader calls the global fetch
        self::script(<<<'JS'
            const real = window.fetch.bind(window);
            window.fetch = (url, options) => (options && options.headers && options.headers['X-Screen'] === '1')
                ? new Promise(resolve => setTimeout(resolve, 1500)).then(() => real(url, options))
                : real(url, options);
            JS);

        self::tap('.tabbar a[href="/korbo26/novinky"]');
        self::waitFor('return document.documentElement.hasAttribute("data-loading");', [], 2);
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/novinky"]\'); return s && !s.hidden && !document.documentElement.hasAttribute("data-loading");');

        self::assertFalse(self::script('return document.documentElement.hasAttribute("data-loading");'));
    }

    /** Lie-fi: a screen fetch that never ends gives way to the plain navigation after 8 s. */
    public function testAFetchThatHangsFallsBackToAPlainNavigation(): void
    {
        self::visit('/korbo26/');
        // every screen fetch hangs until the loader aborts it, as on a connection that never answers
        self::script(<<<'JS'
            window.__noReload = 1;
            const real = window.fetch.bind(window);
            window.fetch = (url, options) => (options && options.headers && options.headers['X-Screen'] === '1')
                ? new Promise((resolve, reject) => {
                    if (options.signal) {
                        options.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
                    }
                })
                : real(url, options);
            JS);

        self::tap('.tabbar a[href="/korbo26/novinky"]');
        // still waiting well before the deadline
        self::waitFor('return document.documentElement.hasAttribute("data-loading");', [], 2);
        self::assertSame(1, self::script('return window.__noReload;'));

        // the document was replaced: the marker is gone and the server rendered Novinky
        self::waitFor('return window.__noReload === undefined && location.pathname === "/korbo26/novinky" && document.readyState === "complete";', [], 15);
        self::assertSame('/korbo26/novinky', self::script('return document.querySelector("[data-screen]").getAttribute("data-screen");'));
    }
}
