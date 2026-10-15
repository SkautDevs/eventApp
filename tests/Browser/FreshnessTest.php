<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/** The line under the app bar says how old the data on the screen is. */
#[Group('browser')]
final class FreshnessTest extends BrowserTestCase
{
    private const string LINE = 'return document.querySelector(\'[data-screen="/korbo26/programy"] > [data-freshness]\');';

    private static function line(): string
    {
        self::waitFor('const line = (function () { ' . self::LINE . ' })(); return line !== null && !line.hidden;');

        return (string) self::script('return (function () { ' . self::LINE . ' })().textContent;');
    }

    public function testTheLineSaysWhenTheDataWasFetched(): void
    {
        self::visit('/korbo26/programy');

        self::assertMatchesRegularExpression('/^aktualizováno \d\d:\d\d$/', self::line());
    }

    public function testAStaleCopySaysSo(): void
    {
        self::visit('/korbo26/programy');
        self::line();

        self::script(<<<'JS'
            const section = document.querySelector('[data-screen="/korbo26/programy"]');
            section.setAttribute('data-stale', '1');
            section.dispatchEvent(new CustomEvent('screen:freshness', {bubbles: true}));
            JS);

        self::assertMatchesRegularExpression('/^naposledy načteno \d\d:\d\d$/', self::line());
    }

    public function testOfflineSaysWhereTheCopyIsFrom(): void
    {
        self::visit('/korbo26/programy');
        self::line();

        self::script("Object.defineProperty(navigator, 'onLine', {get: () => false, configurable: true}); window.dispatchEvent(new Event('offline'));");

        self::assertMatchesRegularExpression('/^offline · z \d\d:\d\d$/', self::line());
    }

    /** The home screen spaces its column evenly, so on a short phone the mark rose under the line. */
    public function testTheHomeMarkStartsBelowTheLine(): void
    {
        self::overrideViewport(375, 667);
        try {
            foreach (['obrok27', 'obrok19'] as $slug) {
                self::visit('/' . $slug . '/');
                self::waitFor('const line = document.querySelector(\'[data-screen="/' . $slug . '/"] > [data-freshness]\'); return line !== null && !line.hidden;');

                $gap = self::script(<<<'JS'
                    const screen = document.querySelector('[data-screen="/' + arguments[0] + '/"]');
                    const line = screen.querySelector(':scope > [data-freshness]').getBoundingClientRect();
                    const mark = Array.from(screen.querySelectorAll('img.mainLogo')).map(img => img.getBoundingClientRect()).find(r => r.height > 0);
                    return mark.top - line.bottom;
                    JS, [$slug]);

                self::assertGreaterThanOrEqual(0, $gap, $slug . ': the mark starts under the line');
            }
        } finally {
            self::overrideViewport(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT);
        }
    }

    /** The timeline scrolls inside itself; the line above it must not make the page scroll too. */
    public function testTheProgramScreenGivesTheLineItsHeightBack(): void
    {
        self::visit('/obrok19/programy');
        self::line19();

        self::assertSame(
            self::script('return window.innerHeight;'),
            self::script('return document.documentElement.scrollHeight;'),
        );
    }

    private static function line19(): void
    {
        self::waitFor('const line = document.querySelector(\'[data-screen="/obrok19/programy"] > [data-freshness]\'); return line !== null && !line.hidden && document.querySelector(\'[data-pg-root][data-pg-ready="1"]\') !== null;');
    }
}
