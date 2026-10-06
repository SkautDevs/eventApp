<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class SkipLinkTest extends BrowserTestCase
{
    /** U-I2: after a swap the skip link's href names the first screen; following it threw the app away. */
    public function testTheSkipLinkFocusesTheVisibleScreenWithoutNavigating(): void
    {
        self::visit('/korbo26/');
        self::script('window.__noReload = 1;');
        self::tap('.tabbar a[href="/korbo26/programy"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); return s && !s.hidden;');

        self::script('document.querySelector(".skip-link").click();');

        self::assertSame('/korbo26/programy', self::script('return location.pathname;'));
        self::assertSame(1, self::script('return window.__noReload;'));
        self::assertSame('/korbo26/programy', self::script('return document.activeElement.getAttribute("data-screen");'));
    }
}
