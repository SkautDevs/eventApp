<?php

declare(strict_types=1);

namespace Tests\Functional;

final class ProgramsTest extends AppTestCase
{
    public function testProgramsPageGroupsBySections(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/programy');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Putování', $html);
        self::assertStringContainsString('Ukázková vycházka', $html);
        self::assertStringNotContainsString('map-vzlet.png', $html); // the Vzlet section has no program → not rendered
        self::assertStringNotContainsString('Osobní volno', $html);
        // sections with no programs are not rendered
        self::assertStringNotContainsString('EXPO', $html);
    }

    /**
     * The hour scale is zoomable, driven by two real buttons rather than by the
     * pinch gesture alone — so it has to work from a keyboard and be named in Czech.
     */
    public function testTimelineCarriesZoomControls(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertStringContainsString('data-pg-zoom="out" aria-label="Oddálit"', $html);
        self::assertStringContainsString('data-pg-zoom="in" aria-label="Přiblížit"', $html);
        // <button> rather than a div, so the tab order and Enter/Space come for free
        self::assertSame(2, substr_count($html, '<button type="button" class="pager-zoom-btn"'));
        // the personal list has no time axis, so its pager gets no zoom
        self::assertSame(1, substr_count($html, 'class="pager-zoom"'));
    }

    /**
     * .highlight used to be both a link the reader should press and a line of text
     * telling them something went wrong. Only the first is a button now, so the
     * notice has to have stopped using that class or it would stretch like one.
     */
    public function testTheProviderNoticeIsNotAButton(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/programs.twig');

        self::assertStringContainsString('<p class="notice">{{ notice }}</p>', $twig);
        self::assertStringNotContainsString('class="highlight"', $twig);
        // the notice keeps the shrink-to-fit box the class used to have
        self::assertMatchesRegularExpression('/\.notice \{[^}]*display: inline-block;/', $css);
        // while the two page actions fill the column inside the existing gutter
        self::assertMatchesRegularExpression('/\.btn, \.btn:visited,\s*\n\.highlight, \.highlight:visited \{[^}]*width: calc\(100% - 16px\);/', $css);
    }

    /**
     * At a dense scale the ruler thins its text to every second hour while every
     * hour keeps its mark. That needs the label to be its own element inside the
     * tick — the mark is a ::before on the tick itself, so hiding the tick would
     * take the mark with it.
     */
    public function testRulerLabelsAreSeparateFromTheirTickMarks(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertMatchesRegularExpression(
            '/<span class="tl-tick" style="left: calc\(var\(--hour-width\) \* [\d.]+\)"><span class="tl-tick-label">\d{2}:\d{2}<\/span><\/span>/',
            $html,
        );
        self::assertSame(substr_count($html, 'class="tl-tick"'), substr_count($html, 'class="tl-tick-label"'));
    }

    /**
     * Everything on this screen is provider data rendered into the document, and the
     * whole client side — the morph's keyed matching, the deep link, the sheet — rests
     * on that document being what the server said it was. Twig autoescapes, and this is
     * the guard that keeps it that way: a programme name is text, never markup.
     */
    public function testAProgrammeNameIsRenderedAsTextAndNeverAsMarkup(): void
    {
        $html = (string) $this->request($this->createApp('programs', fixtureEvent: true), 'GET', '/programy')->getBody();

        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        // the sheet carries the same data through a second set of fields
        self::assertStringNotContainsString('<script>alert(2)</script>', $html);
        self::assertStringNotContainsString('<b>Nebezpecny perex</b>', $html);
    }

    /**
     * A record whose start does not parse used to become 1970-01-01 — its own page,
     * sorted first, and the page the screen opened on, because the event is not today.
     * The good records still render and the screen opens on their day.
     */
    public function testARecordWithAnUnusableDateNeitherRendersNorMovesTheScreen(): void
    {
        $html = (string) $this->request($this->createApp('programs', fixtureEvent: true), 'GET', '/programy')->getBody();

        self::assertStringNotContainsString('page-1970', $html);
        self::assertStringNotContainsString('Bez zacatku', $html);
        self::assertStringNotContainsString('Bez konce', $html);
        self::assertStringContainsString('Hodny program', $html);
        self::assertStringContainsString('<section class="tl-page is-active" data-morph-keep="class" data-pg-panel="timeline" data-key="page-20270603-1"', $html);
    }

    /**
     * The screen opens on today, and between midnight and 02:00 in summer "today" is a
     * different date in UTC than it is in Prague — which is exactly when a camper checks
     * what is on tomorrow. Booting the app pins the zone, so date() answers the event's
     * calendar rather than the server's. Nothing else in the suite can see this: every
     * other use of time reads and formats in the same zone and is shifted alike.
     */
    public function testBootingTheAppPinsTheEventsTimezone(): void
    {
        date_default_timezone_set('UTC');

        $this->createApp();

        self::assertSame('Europe/Prague', date_default_timezone_get());
    }

    /** Two records sharing an id are one programme on the screen, not two cards and one sheet. */
    public function testADuplicateIdIsDrawnOnce(): void
    {
        $html = (string) $this->request($this->createApp('programs', fixtureEvent: true), 'GET', '/programy')->getBody();

        self::assertSame(1, substr_count($html, 'data-pg-open="2"'));
        self::assertSame(1, substr_count($html, 'data-pg-detail="2"'));
        self::assertStringNotContainsString('Duplicitni id', $html);
    }
}
