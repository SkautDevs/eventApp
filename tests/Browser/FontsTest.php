<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The browser lane resolves no host but the test server's, so a font that still came
 * from a CDN would be missing here exactly as it is missing offline.
 */
#[Group('browser')]
final class FontsTest extends BrowserTestCase
{
    /**
     * Whether a face of $family (quotes ignored) covering $weight has actually loaded;
     * a variable face declares a range, "400 700".
     */
    private const LOADED = <<<'JS'
        const [family, weight] = arguments;
        return [...document.fonts].some(f => {
            const [lo, hi = lo] = String(f.weight).split(' ').map(Number);
            return f.family.replace(/["']/g, '') === family && lo <= Number(weight) && Number(weight) <= hi && f.status === 'loaded';
        });
        JS;

    public function testTheIconsLoadFromTheAppItself(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');
        self::asyncScript('const done = arguments[arguments.length - 1]; document.fonts.ready.then(() => done(true));');

        self::assertStringContainsString(
            'Font Awesome 5 Free',
            (string) self::script('return getComputedStyle(document.querySelector(".tabbar a i, .tabbar a .fas, .tabbar a .far")).fontFamily;'),
        );
        self::waitFor(self::LOADED, ['Font Awesome 5 Free', '900']);
        self::assertTrue(self::script('return document.fonts.check(\'900 16px "Font Awesome 5 Free"\');'));
        self::assertGreaterThan(0, (float) self::script('return document.querySelector(".appbar-profile i").getBoundingClientRect().width;'));
    }

    public function testTheShellsOwnTypefaceLoads(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        self::waitFor(self::LOADED, ['themix', '400']);
        self::assertTrue(self::script('return document.fonts.check(\'16px themix\');'));
    }

    public function testObrok27sMontserratLoads(): void
    {
        self::visit('/obrok27/');
        self::waitFor('return document.readyState === "complete";');

        self::waitFor(self::LOADED, ['Montserrat', '400']);
        self::assertTrue(self::script('return document.fonts.check(\'16px Montserrat\');'));
    }
}
