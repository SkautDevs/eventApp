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
}
