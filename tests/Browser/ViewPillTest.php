<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The Program screen's view pill holds three labels — Přehled, Harmonogram, Moje — in
 * the width two used to have. Every label must show whole, in its regular weight and
 * in the bold it takes while selected, on every event with a Program screen, in the
 * event's own fonts, from the narrowest 320px phone up.
 */
#[Group('browser')]
final class ViewPillTest extends BrowserTestCase
{
    private const array EVENTS = ['korbo26', 'obrok27', 'navigamus27', 'miquik26', 'obrok19'];

    private const array WIDTHS = [320, 360, 390];

    private const array VIEWS = ['overview', 'timeline', 'list'];

    /** Every label of the pill whose text is wider than its box, measured once the fonts are in. */
    private const string CLIPPED = <<<'JS'
        const done = arguments[arguments.length - 1];
        document.fonts.ready.then(() => {
            const clipped = [];
            document.querySelectorAll('[data-pg-root] .tabs-tab').forEach(tab => {
                const label = tab.querySelector('.tabs-label');
                const t = tab.getBoundingClientRect();
                const bar = tab.parentElement.getBoundingClientRect();
                if (label.scrollWidth > label.clientWidth || t.right > bar.right + 0.5 || t.left < bar.left - 0.5) {
                    clipped.push(label.textContent + ' ' + label.scrollWidth + '>' + label.clientWidth
                        + ' (' + getComputedStyle(label).fontWeight + ')');
                }
            });
            done(clipped);
        });
        JS;

    public function testEveryLabelFitsWholeFrom320Up(): void
    {
        $clipped = [];
        try {
            foreach (self::WIDTHS as $width) {
                self::overrideViewport($width, 800);
                foreach (self::EVENTS as $slug) {
                    self::visit('/' . $slug . '/programy');
                    self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
                    self::assertSame($width, self::script('return window.innerWidth;'), 'the viewport override did not take');
                    foreach (self::VIEWS as $view) {
                        self::script('document.querySelector(\'[data-pg-view="' . $view . '"]\').click();');
                        self::waitFor('return document.querySelector(\'[data-pg-root]\').dataset.view === "' . $view . '";');
                        foreach (self::asyncScript(self::CLIPPED) as $label) {
                            $clipped[] = $slug . ' at ' . $width . 'px, ' . $view . ' selected: ' . $label;
                        }
                    }
                }
            }
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
        self::assertSame([], $clipped);
    }

    /** The pill's selected fill sits exactly under the selected segment, whatever its width. */
    public function testTheIndicatorCoversTheSelectedSegment(): void
    {
        try {
            self::overrideViewport(360, 800);
            self::visit('/obrok27/programy');
            self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
            foreach (self::VIEWS as $view) {
                self::script('document.querySelector(\'[data-pg-view="' . $view . '"]\').click();');
                self::waitFor(<<<'JS'
                    const tab = document.querySelector('[data-pg-root] .tabs-tab[aria-selected="true"]').getBoundingClientRect();
                    const fill = document.querySelector('[data-pg-root] .tabs-indicator').getBoundingClientRect();
                    return document.querySelector('[data-pg-root]').dataset.view === arguments[0]
                        && Math.abs(tab.left - fill.left) < 1 && Math.abs(tab.right - fill.right) < 1;
                    JS, [$view]);
            }
            self::addToAssertionCount(1);
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
    }

    /**
     * No view widens the page: a phone that can scroll sideways moves the fixed tab bar and
     * the docked pill off the screen. obrok27's fixtures carry a name that is one unbroken
     * run of 366 characters, which once made Přehled 2257px wide at 358px.
     */
    public function testNoViewScrollsThePageSideways(): void
    {
        $wide = [];
        try {
            self::overrideViewport(320, 700);
            foreach (self::EVENTS as $slug) {
                self::visit('/' . $slug . '/programy');
                self::waitFor('return document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
                foreach (self::VIEWS as $view) {
                    self::script('document.querySelector(\'[data-pg-view="' . $view . '"]\').click();');
                    self::waitFor('return document.querySelector(\'[data-pg-root]\').dataset.view === "' . $view . '";');
                    $width = self::script('return document.documentElement.scrollWidth;');
                    if ($width > 320) {
                        $wide[] = $slug . ' ' . $view . ': ' . $width . 'px';
                    }
                }
            }
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
        self::assertSame([], $wide);
    }
}
