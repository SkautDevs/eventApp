<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * Můj program is one continuous scroll over the whole event.
 *
 * It used to page by day, one .pl-day visible at a time. Now every day is present and
 * visible in document order, each under its own sticky heading, and the strip of day
 * chips on top follows the scroll; a tap on a chip scrolls to its day. The timeline
 * still pages, by day, through a strip of the same chips.
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

    public function testListItemsCarryTheirTimesAsInstants(): void
    {
        $html = $this->loggedInScreen();

        preg_match_all('#<article class="pl-item" data-key="\d+" data-start="([^"]+)" data-end="([^"]+)"#', $html, $m, PREG_SET_ORDER);
        self::assertNotEmpty($m);
        foreach ($m as [, $start, $end]) {
            self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $start));
            self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $end));
        }
        // "now" is the reader's clock's business, never the server's
        self::assertDoesNotMatchRegularExpression('#class="pl-item"[^>]*\sdata-now=#', $html);
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
    }

    /** The days keep the order of the event, first to last, because the reader scrolls them. */
    public function testTheDaysAreInDocumentOrder(): void
    {
        $html = $this->loggedInScreen();

        self::assertGreaterThan(0, strpos($html, 'day-20190530'));
        self::assertGreaterThan((int) strpos($html, 'day-20190530'), (int) strpos($html, 'day-20190531'));
        self::assertGreaterThan((int) strpos($html, 'day-20190531'), (int) strpos($html, 'day-20190601'));
        // the strip opens on the day at the top of the scroll, not today
        self::assertMatchesRegularExpression('#data-pg-page="day-20190530" data-pg-kind="list" aria-current="true" data-morph-keep="aria-current">čt 30. 5.</button>#u', $html);
    }

    /** Both views pick the day from a strip of chips; no arrows, no day dialog. */
    public function testBothViewsPickTheDayFromAStripOfChips(): void
    {
        $html = $this->loggedInScreen();

        foreach (['timeline', 'list'] as $kind) {
            self::assertMatchesRegularExpression('#<div class="days" role="group" aria-label="Dny" data-pg-days="' . $kind . '">#', $html);
        }
        self::assertStringNotContainsString('pager-menu', $html);
        self::assertStringNotContainsString('data-pg-step', $html);
        self::assertStringNotContainsString('data-pg-list-top', $html);
        // exactly one chip per strip is current, and the morph leaves the reader's choice alone
        self::assertSame(2, substr_count($html, 'aria-current="true" data-morph-keep="aria-current"'));
        // the zoom pair belongs to the axis, so it stays on the timeline's row alone
        self::assertSame(1, substr_count($html, 'class="pager-zoom"'));
    }

    public function testTheChipsAreFullSizedTouchTargets(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        self::assertMatchesRegularExpression('/\.day-chip\s*\{[^}]*min-height:\s*44px/s', $css);
        self::assertMatchesRegularExpression('/\.day-chip\s*\{[^}]*min-width:\s*44px/s', $css);
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
