<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\Identity;
use App\Program\ProgramProviderInterface;

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

        self::assertStringContainsString('<p class="notice" data-key="notice">{{ notice }}</p>', $twig);
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
        self::assertStringContainsString('<section class="tl-page is-active" data-morph-keep="class" data-pg-panel="timeline" data-key="page-20270603"', $html);
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

    /**
     * Sections come from the provider, presentation and all. kissj sends its map and
     * attachment as absolute URLs, where the stub's fixtures carry paths relative to www/;
     * the page's <base href="/"> resolves the latter and leaves the former alone, so the
     * sheet has to print both exactly as given.
     */
    public function testSectionPresentationFromTheProviderRendersInTheSheet(): void
    {
        $html = $this->programsWith([7 => [
            'id' => 7,
            'title' => 'Velká hra',
            'subTitle' => '1. blok',
            'image' => 'https://kissj.example/img/map.png',
            'attachment' => ['href' => 'https://kissj.example/files/rules.pdf', 'label' => 'Pravidla'],
        ]]);

        self::assertStringContainsString('Hra v lese', $html);
        self::assertStringContainsString('<p class="sheet-section">Velká hra – 1. blok</p>', $html);
        self::assertStringContainsString('<img class="sheet-map" src="https://kissj.example/img/map.png"', $html);
        self::assertStringContainsString('<a class="sheet-link" href="https://kissj.example/files/rules.pdf">Pravidla</a>', $html);
    }

    /** A programme in a section the provider did not list gets no place on the timeline. */
    public function testAProgrammeInAnUnlistedSectionIsNotOnTheTimeline(): void
    {
        $listed = $this->programsWith([7 => ['id' => 7, 'title' => 'Velká hra', 'subTitle' => null, 'image' => null, 'attachment' => null]]);
        $unlisted = $this->programsWith([8 => ['id' => 8, 'title' => 'Jiná', 'subTitle' => null, 'image' => null, 'attachment' => null]]);

        self::assertStringContainsString('data-pg-open="1"', $listed);
        self::assertStringNotContainsString('data-pg-open="1"', $unlisted);
        self::assertStringNotContainsString('class="tl-page', $unlisted);
    }

    /**
     * eventApp filters nothing by name: kissj sends only what belongs on the schedule, so
     * a programme that arrives is shown, whatever it is called — "Osobní volno" included.
     */
    public function testEveryProgrammeTheProviderSendsIsShown(): void
    {
        $html = $this->programsWith(
            [7 => ['id' => 7, 'title' => 'Velká hra', 'subTitle' => null, 'image' => null, 'attachment' => null]],
            name: 'Osobní volno',
        );

        self::assertStringContainsString('data-pg-open="1"', $html);
        self::assertStringContainsString('Osobní volno', $html);
    }

    /** Renders /programy over one programme in section 7 and the given sections. */
    private function programsWith(array $sections, string $name = 'Hra v lese'): string
    {
        $provider = new class ($sections, $name) implements ProgramProviderInterface {
            public function __construct(private readonly array $sections, private readonly string $name)
            {
            }

            public function getPrograms(): array
            {
                return [[
                    'id' => 1,
                    'name' => $this->name,
                    'section' => ['id' => 7],
                    'start' => ['date' => '2027-06-03 09:00:00'],
                    'end' => ['date' => '2027-06-03 10:00:00'],
                    'location' => 'Les',
                ]];
            }

            public function getSections(): array
            {
                return $this->sections;
            }

            public function getProgramsForIdentity(Identity $identity): array
            {
                return [];
            }

            public function getTieCodesForProgramme(int $programmeId): array
            {
                return [];
            }
        };

        return (string) $this->request(
            $this->createApp(overrides: [ProgramProviderInterface::class => $provider]),
            'GET',
            '/programy',
        )->getBody();
    }

    /** The two views are named for what they are to a reader: the whole schedule, and theirs. */
    public function testTheViewTabsAreNamedHarmonogramAndMujProgram(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertStringContainsString('<span class="tabs-label">Harmonogram</span>', $html);
        self::assertStringContainsString('<span class="tabs-label">Můj program</span>', $html);
        self::assertStringNotContainsString('>Timeline<', $html);
        self::assertStringNotContainsString('>Seznam<', $html);
        // the keys the script and the stylesheet read are not copy, and stay
        self::assertStringContainsString('data-pg-view="timeline"', $html);
        self::assertStringContainsString('data-pg-view="list"', $html);
    }

    /** Every reader-facing line on the screen addresses the reader as ty. */
    public function testTheScreenSpeaksInTheTyRegister(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertStringContainsString('Přihlas se TIE kódem a uvidíš tady svůj vlastní program.', $html);
        foreach (['Přihlaste', 'uvidíte', 'nemáte', 'Váš program', 'zkuste'] as $vy) {
            self::assertStringNotContainsString($vy, $html);
        }
    }

    /** The sheet is named by the programme it shows, not by a constant. */
    public function testTheSheetIsLabelledByAProgrammeName(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertSame(1, preg_match('/<div class="sheet-card"[^>]*>/', $html, $card));
        self::assertStringContainsString('role="dialog"', $card[0]);
        self::assertStringContainsString('aria-modal="true"', $card[0]);
        self::assertStringNotContainsString('aria-label=', $card[0]);
        self::assertSame(1, preg_match('/aria-labelledby="(sheet-name-\d+)"/', $card[0], $ref));
        self::assertStringContainsString('<h2 class="sheet-name" id="' . $ref[1] . '">', $html);
        self::assertSame(substr_count($html, 'class="sheet-body"'), substr_count($html, '<h2 class="sheet-name" id="sheet-name-'));
    }

    /** U-M4: a personal list that could not be read dims nothing and claims nothing. */
    public function testAFailedPersonalListDimsNothing(): void
    {
        $stub = new \App\Program\StubProgramProvider(dirname(__DIR__, 2) . '/events/obrok19/fixtures');
        $failing = new class ($stub) implements ProgramProviderInterface {
            public function __construct(private ProgramProviderInterface $inner)
            {
            }

            public function getPrograms(): array
            {
                return $this->inner->getPrograms();
            }

            public function getSections(): array
            {
                return $this->inner->getSections();
            }

            public function getProgramsForIdentity(Identity $identity): array
            {
                throw new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('GET', 'x'));
            }

            public function getTieCodesForProgramme(int $programmeId): array
            {
                return [];
            }
        };
        $app = $this->createApp('obrok19', [ProgramProviderInterface::class => $failing]);
        $_SESSION['obrok19']['identity'] = ['displayName' => 'TIE ABC123', 'tieCode' => 'ABC123'];
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        self::assertStringContainsString('Osobní program se nepodařilo načíst.', $html);
        self::assertStringNotContainsString('is-dimmed', $html);
        self::assertStringNotContainsString('Zatím nemáš přihlášený žádný program.', $html);
    }

    /** U-M5: with no programme list the outage notice speaks alone. */
    public function testAnOutageShowsNoEmptyLine(): void
    {
        $down = new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('GET', 'x'));
        $app = $this->createApp('obrok19', [ProgramProviderInterface::class => new ThrowingProgramProvider(programsException: $down)]);
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();
        self::assertStringContainsString('Programy se nepodařilo načíst', $html);
        self::assertStringNotContainsString('Program zatím není k dispozici.', $html);
    }

    /**
     * Each timeline page states its axis as two instants with their offset, so the client
     * can place "now" on it whatever time zone the phone is set to.
     */
    public function testEveryPageCarriesItsAxisAsInstants(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/programy')->getBody();

        preg_match_all('#<section class="tl-page[^"]*"[^>]*data-axis-start="([^"]+)" data-axis-end="([^"]+)"#', $html, $m, PREG_SET_ORDER);
        self::assertNotEmpty($m);
        foreach ($m as [, $start, $end]) {
            $s = \DateTimeImmutable::createFromFormat(DATE_ATOM, $start);
            $e = \DateTimeImmutable::createFromFormat(DATE_ATOM, $end);
            self::assertNotFalse($s);
            self::assertNotFalse($e);
            self::assertSame('00', $s->format('i'), 'the axis starts on a whole hour');
            self::assertGreaterThan($s, $e);
        }
        self::assertStringContainsString('<div class="tl-now" aria-hidden="true"></div>', $html);
        self::assertStringContainsString('data-pg-now-label', $html);
        // the server never marks "now": that is the reader's clock's business
        self::assertDoesNotMatchRegularExpression('#class="tl-page[^>]*\sdata-now[\s=>]#', $html);
    }
}
