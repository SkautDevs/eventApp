<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * A reader who asks for large text gets it in the shell's copy, and nothing spills
 * sideways. The bars keep their height and the grid instrument keeps its px type.
 */
#[Group('browser')]
final class TypeScaleTest extends BrowserTestCase
{
    private const string ROOT = 'document.documentElement.style.fontSize = "24px"; void document.body.offsetWidth;';

    public function testTheViewportIsThePhoneAskedFor(): void
    {
        self::visit('/obrok19/novinky');

        self::assertSame(412, self::script('return window.innerWidth;'), 'the overflow checks below assume the phone width BrowserTestCase asks for');
    }

    public function testTheOverflowMeasurementCanFail(): void
    {
        self::visit('/obrok19/novinky');
        self::script(self::ROOT);
        $overflow = self::script('const probe = document.createElement("div"); probe.style.width = "2000px"; probe.style.height = "1px"; document.querySelector(".screen").appendChild(probe); const r = document.documentElement.scrollWidth > document.documentElement.clientWidth; probe.remove(); return r;');

        self::assertTrue($overflow, 'scrollWidth does not see overflow here, so the checks below would prove nothing');
    }

    public function testA24pxRootScalesTheCopyWithoutOverflow(): void
    {
        foreach (['/obrok19/', '/obrok19/profil', '/obrok19/novinky', '/obrok19/odkazy'] as $path) {
            self::visit($path);
            self::waitFor('return document.readyState === "complete";');
            self::script(self::ROOT);

            self::assertSame(
                self::script('return document.documentElement.clientWidth;'),
                self::script('return document.documentElement.scrollWidth;'),
                $path . ' overflows sideways at a 24px root',
            );
            self::assertSame(52.0, (float) self::script('return document.querySelector(".appbar").getBoundingClientRect().height;'), $path . ': the app bar changed height');
            self::assertSame('30px', self::script('return getComputedStyle(document.querySelector(".appbar-title")).fontSize;'), $path . ': the title is not on the scale');
        }
    }

    public function testTheGridInstrumentKeepsItsPxType(): void
    {
        self::visit('/obrok19/programy');
        self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
        self::script(self::ROOT);

        self::assertSame('14px', self::script('return getComputedStyle(document.querySelector(".tl-card")).fontSize;'));
        self::assertSame('24px', self::script('return getComputedStyle(document.querySelector(".tabs-tab")).fontSize;'));
    }
}
