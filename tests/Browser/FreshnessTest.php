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
}
