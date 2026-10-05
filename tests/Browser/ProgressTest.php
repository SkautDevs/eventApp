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
}
