<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * Můj program is one continuous scroll over the whole event.
 *
 * It used to page by day, one .pl-day visible at a time. Now every day is present and
 * visible in document order, each under its own sticky heading, and the strip on top
 * stops being a pager: the label follows the scroll and the arrows jump between day
 * headings. Only that strip changed — the timeline still pages, by day.
 */
final class ProgramListTest extends AppTestCase
{
    /** tie:ABC123 is registered for eight programmes across three days of obrok19. */
    private function loggedInScreen(): string
    {
        $app = $this->createApp();
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);

        return (string) $this->request($app, 'GET', '/programy')->getBody();
    }

    public function testEveryDayIsRenderedAndNoneIsSingledOut(): void
    {
        $html = $this->loggedInScreen();

        self::assertSame(3, substr_count($html, '<section class="pl-day"'));
        // the class that used to mean "the page you are on" is gone from the list:
        // there is no page any more, so nothing to be on
        self::assertStringNotContainsString('pl-day is-active', $html);
        // and each day names itself inside the scroll
        self::assertSame(3, substr_count($html, '<h2 class="pl-head">'));
        self::assertStringContainsString('<h2 class="pl-head">čt 30. 5.</h2>', $html);
        self::assertStringContainsString('<h2 class="pl-head">so 1. 6.</h2>', $html);
        // the observer's handle on the top of the scroll, which is what disables ↑ there
        self::assertStringContainsString('data-pg-list-top', $html);
    }

    /** The days keep the order of the event, first to last, because the reader scrolls them. */
    public function testTheDaysAreInDocumentOrder(): void
    {
        $html = $this->loggedInScreen();

        self::assertGreaterThan(0, strpos($html, 'day-20190530'));
        self::assertGreaterThan((int) strpos($html, 'day-20190530'), (int) strpos($html, 'day-20190531'));
        self::assertGreaterThan((int) strpos($html, 'day-20190531'), (int) strpos($html, 'day-20190601'));
        // the strip opens naming the day at the top of the scroll, not today
        self::assertStringContainsString('<span data-pg-title="list">čt 30. 5.</span>', $html);
    }

    /**
     * Two strips, two instruments. Only the list's changed shape: the timeline keeps
     * its chevrons, its day pages and its zoom pair.
     */
    public function testOnlyTheListStripBecameAnOrientationStrip(): void
    {
        $html = $this->loggedInScreen();

        self::assertStringContainsString('data-pg-step="-1" data-pg-kind="list" aria-label="Předchozí den" disabled>↑', $html);
        self::assertStringContainsString('data-pg-step="1" data-pg-kind="list" aria-label="Další den">↓', $html);
        self::assertStringContainsString('data-pg-step="-1" data-pg-kind="timeline" aria-label="Předchozí">‹', $html);
        self::assertStringContainsString('data-pg-step="1" data-pg-kind="timeline" aria-label="Další">›', $html);
        // the zoom pair belongs to the axis, so it stays on the timeline's row alone
        self::assertSame(1, substr_count($html, 'class="pager-zoom"'));
    }

    /**
     * 44px minimum on both strips: the timeline's row gave the width back from its
     * label, which keeps 144px on a 320px phone.
     */
    public function testTheDayArrowsAreFullSizedTouchTargets(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        // both strips' arrows are 44px wide now; the day arrows only keep their own glyph size
        self::assertMatchesRegularExpression('/\.pager-arrow \{[^}]*flex: 0 0 44px;/', $css);
        self::assertMatchesRegularExpression('/\.pager-arrow \{[^}]*height: 44px;/', $css);
        self::assertDoesNotMatchRegularExpression('/\.pager-arrow-day \{[^}]*flex:/', $css);
    }

    /** Every day is visible: nothing in the list is display:none any more. */
    public function testTheStylesheetNoLongerHidesADay(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        self::assertStringNotContainsString('.tl-page, .pl-day', $css);
        self::assertStringNotContainsString('.pl-day.is-active', $css);
        // The heading docks under the strip rather than scrolling away with its day —
        // against the same line the strip itself is docked against, the notch included.
        // bandTop() in www/programs.js adds up the same three tokens, and the two have
        // to agree or the strip names one day while another one's heading is stuck.
        self::assertMatchesRegularExpression(
            '/\.pl-head \{[^}]*position: sticky;[^}]*top: calc\(var\(--appbar-height\) \+ var\(--appbar-inset\) \+ var\(--pager-height\)\);/',
            $css,
        );
    }

    /** Logged out there is nothing personal to scroll, exactly as before. */
    public function testLoggedOutTheListIsStillEmpty(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertStringNotContainsString('class="pl-day"', $html);
        self::assertStringNotContainsString('data-pg-pager="list"', $html);
        self::assertStringContainsString('Přihlas se TIE kódem', $html);
    }

    /** Logged out, the list asks for the TIE code in place instead of sending the reader to /profil. */
    public function testLoggedOutTheListCarriesTheTieForm(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertStringContainsString('action="/obrok19/profil/tie"', $html);
        self::assertStringContainsString('name="tieCode"', $html);
        self::assertStringContainsString('<input type="hidden" name="return" value="programy">', $html);
    }

    public function testALoginFromTheListLandsBackOnTheList(): void
    {
        $response = $this->request($this->createApp(), 'POST', '/profil/tie', ['tieCode' => 'ABC123', 'return' => 'programy']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/obrok19/programy#muj-program', $response->getHeaderLine('Location'));
    }

    public function testAFailedLoginFromTheListIsExplainedOnTheList(): void
    {
        $app = $this->createApp();
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'NOPE99', 'return' => 'programy']);
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        self::assertStringContainsString('Neplatný TIE kód.', $html);
        // shown once, then gone
        $again = (string) $this->request($app, 'GET', '/programy')->getBody();
        self::assertStringNotContainsString('Neplatný TIE kód.', $again);
    }

    /** Only a known screen is a return target; anything else keeps the old /profil redirect. */
    public function testAnUnknownReturnFallsBackToTheProfile(): void
    {
        $response = $this->request($this->createApp(), 'POST', '/profil/tie', ['tieCode' => 'ABC123', 'return' => 'https://evil.example']);

        self::assertSame('/obrok19/profil', $response->getHeaderLine('Location'));
    }
}
