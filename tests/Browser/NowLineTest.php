<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The current-time line on the Program timeline, against a clock pinned inside the
 * korbo26 camp (16–20 Sep 2026). The 17th's axis runs 00:00–24:00 and the 19th's
 * 08:00–24:00, so every pinned time below lies on a drawn axis.
 */
#[Group('browser')]
final class NowLineTest extends BrowserTestCase
{
    private const string ACTIVE_PAGE_HAS_NOW = 'return !!document.querySelector(".tl-page.is-active[data-now]");';

    public function testTheLineSitsOnTodaysPageAtTheRightHour(): void
    {
        self::pinClock('2026-09-17T14:30:00+02:00');
        self::visit('/korbo26/programy');
        self::waitFor(self::ACTIVE_PAGE_HAS_NOW);

        $state = self::script(<<<'JS'
            var page = document.querySelector('.tl-page.is-active');
            var start = Date.parse(page.dataset.axisStart);
            return {
                key: page.dataset.key,
                offset: parseFloat(page.style.getPropertyValue('--now-offset')),
                expected: (Date.parse('2026-09-17T14:30:00+02:00') - start) / 3600000,
                label: page.querySelector('[data-pg-now-label]').textContent,
                pages: document.querySelectorAll('.tl-page[data-now]').length,
                line: getComputedStyle(page.querySelector('.tl-now')).display,
            };
        JS);
        self::assertSame('page-20260917', $state['key']);
        self::assertEqualsWithDelta($state['expected'], $state['offset'], 0.001);
        self::assertSame('14:30', $state['label']);
        self::assertSame(1, $state['pages']);
        self::assertSame('block', $state['line']);
    }

    public function testTheLineMovesWithTheClockAndTheZoom(): void
    {
        self::pinClock('2026-09-17T14:30:00+02:00');
        self::visit('/korbo26/programy');
        self::waitFor(self::ACTIVE_PAGE_HAS_NOW);
        $x = 'return document.querySelector(".tl-page.is-active .tl-now").getBoundingClientRect().left;';
        $before = self::script($x);
        self::script('document.querySelector(\'[data-pg-zoom="in"]\').click();');
        // the zoom holds the centre, and the line moves with the scale rather than staying put
        self::script('window.pgClock = function () { return Date.parse("2026-09-17T15:30:00+02:00"); }; document.dispatchEvent(new Event("pg:tick"));');
        self::waitFor('return document.querySelector(".tl-page.is-active [data-pg-now-label]").textContent === "15:30";');
        self::assertNotEquals($before, self::script($x));
    }

    public function testOutsideTheEventThereIsNoLineAndNoJump(): void
    {
        self::pinClock('2026-12-24T12:00:00+01:00');
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(".pg").dataset.pgReady === "1";');
        self::assertSame(0, self::script('return document.querySelectorAll(".tl-page[data-now]").length;'));
        self::assertSame(0, self::script('return document.querySelector(".tl-page.is-active .tl-scroll").scrollLeft;'));
    }

    /** Review Focus 1: the server picked another page (its own date), the client knows better. */
    public function testAStaleCopyStillOpensOnTodaysPage(): void
    {
        // the test server's date is not in 2026-09, so its activePage is the first page
        self::pinClock('2026-09-19T10:00:00+02:00');
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(".tl-page.is-active").dataset.key === "page-20260919";');
        self::assertGreaterThan(0, self::script('return document.querySelector(".tl-page.is-active .tl-scroll").scrollLeft;'));
    }

    /** Review Focus 2: a phone on UTC still reads camp time. */
    public function testTheLabelIsPragueTimeOnAUtcPhone(): void
    {
        self::devTools()->execute('Emulation.setTimezoneOverride', ['timezoneId' => 'UTC']);
        try {
            self::pinClock('2026-09-17T14:30:00+02:00');
            self::visit('/korbo26/programy');
            // the phone really is on UTC: its own clock reads two hours earlier
            self::assertSame(12, self::script('return new Date(window.pgClock()).getHours();'));
            self::waitFor('return document.querySelector(".tl-page.is-active [data-pg-now-label]").textContent === "14:30";');
            self::assertSame('page-20260917', self::script('return document.querySelector(".tl-page.is-active").dataset.key;'));
        } finally {
            self::devTools()->execute('Emulation.setTimezoneOverride', ['timezoneId' => '']);
        }
    }
}
