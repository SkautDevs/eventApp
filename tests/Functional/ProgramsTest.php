<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Module\Programs\View\Sheet;
use App\Program\ProgramProviderInterface;
use Tests\CoreStylesheet;

final class ProgramsTest extends AppTestCase
{
    public function testProgramsPageGroupsBySections(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/programy');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Putování', $html);
        self::assertStringContainsString('Ukázková vycházka', $html);
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
        $css = CoreStylesheet::read();
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/programs.twig');

        self::assertStringContainsString('<p class="notice" data-key="notice">{{ notice }}</p>', $twig);
        self::assertStringNotContainsString('class="highlight"', $twig);
        // the notice keeps the shrink-to-fit box the class used to have
        self::assertMatchesRegularExpression('/\.notice \{[^}]*display: inline-block;/', $css);
        // while the two page actions fill the column inside the existing gutter
        self::assertMatchesRegularExpression('/\.btn,\s*\.btn:visited,\s*\.highlight,\s*\.highlight:visited \{[^}]*width: calc\(100% - 16px\);/', $css);
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

        // one card on the timeline, one row in Přehled, one sheet
        self::assertSame(1, preg_match_all('/<a class="tl-card[^"]*" href="[^"]*" data-key="2"/', $html));
        self::assertSame(1, preg_match_all('/<article class="pl-item[^"]*" data-key="2"/', $html));
        self::assertSame(1, substr_count($html, 'data-pg-detail="2"'));
        self::assertStringNotContainsString('Duplicitni id', $html);
    }

    /** Sections come from the provider, presentation and all. */
    public function testSectionPresentationFromTheProviderRendersInTheSheet(): void
    {
        $html = $this->programsWith([7 => [
            'id' => 7,
            'title' => 'Velká hra',
            'subTitle' => '1. blok',
        ]]);

        self::assertStringContainsString('Hra v lese', $html);
        self::assertStringContainsString('<span class="sheet-chip">Velká hra – 1. blok</span>', $html);
    }

    /** A programme in a section the provider did not list gets no place on the timeline. */
    public function testAProgrammeInAnUnlistedSectionIsNotOnTheTimeline(): void
    {
        $listed = $this->programsWith([7 => ['id' => 7, 'title' => 'Velká hra', 'subTitle' => null]]);
        $unlisted = $this->programsWith([8 => ['id' => 8, 'title' => 'Jiná', 'subTitle' => null]]);

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
            [7 => ['id' => 7, 'title' => 'Velká hra', 'subTitle' => null]],
            name: 'Osobní volno',
        );

        self::assertStringContainsString('data-pg-open="1"', $html);
        self::assertStringContainsString('Osobní volno', $html);
    }

    /** Renders /programy over one programme in section 7 and the given sections. */
    private function programsWith(array $sections, string $name = 'Hra v lese'): string
    {
        $provider = new class ($sections, $name) implements ProgramProviderInterface {
            public function __construct(private readonly array $sections, private readonly string $name) {}

            public function getPrograms(): array
            {
                return [\App\Program\Programme::fromArray([
                    'id' => 1,
                    'name' => $this->name,
                    'section' => ['id' => 7],
                    'start' => ['date' => '2027-06-03 09:00:00'],
                    'end' => ['date' => '2027-06-03 10:00:00'],
                    'location' => 'Les',
                ]) ?? throw new \LogicException('not a programme row')];
            }

            public function getSections(): array
            {
                return array_map(\App\Program\Section::fromArray(...), $this->sections);
            }

            public function getPlaces(): array
            {
                return [];
            }

            public function getRegistrationWindow(): \App\Program\RegistrationWindow
            {
                return new \App\Program\RegistrationWindow(null, null);
            }

            public function getParticipant(string $sessionToken): \App\Program\Participant
            {
                return new \App\Program\Participant(1, null, []);
            }

            public function getParticipantIdsForProgramme(int $programmeId): array
            {
                return [];
            }

            public function startPairing(string $deviceId): \App\Program\Pairing
            {
                throw new \LogicException('not used by this test');
            }

            public function pollPairing(string $deviceCode): \App\Program\PairingPoll
            {
                throw new \LogicException('not used by this test');
            }

            public function register(string $sessionToken, int $programmeId): void
            {
                throw new \LogicException('not used by this test');
            }

            public function unregister(string $sessionToken, int $programmeId): void
            {
                throw new \LogicException('not used by this test');
            }

            public function revokeSession(string $sessionToken): void
            {
                throw new \LogicException('not used by this test');
            }
        };

        return (string) $this->request(
            $this->createApp(overrides: [ProgramProviderInterface::class => $provider]),
            'GET',
            '/programy',
        )->getBody();
    }

    /** The three views are named for what they are to a reader: the whole list, the schedule, and theirs. */
    public function testTheViewTabsAreNamedPrehledHarmonogramAndMoje(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertStringContainsString('<span class="tabs-label">Přehled</span>', $html);
        self::assertStringContainsString('<span class="tabs-label">Harmonogram</span>', $html);
        self::assertStringContainsString('<span class="tabs-label">Moje</span>', $html);
        self::assertStringNotContainsString('>Timeline<', $html);
        self::assertStringNotContainsString('>Seznam<', $html);
        // the keys the script and the stylesheet read are not copy, and stay
        self::assertStringContainsString('data-pg-view="overview"', $html);
        self::assertStringContainsString('data-pg-view="timeline"', $html);
        self::assertStringContainsString('data-pg-view="list"', $html);
    }

    /** Every reader-facing line on the screen addresses the reader as ty. */
    public function testTheScreenSpeaksInTheTyRegister(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertStringContainsString('Pro svůj program se přihlas kódem z KissJ.', $html);
        foreach (['Přihlaste', 'uvidíte', 'nemáte', 'Váš program', 'zkuste'] as $vy) {
            self::assertStringNotContainsString($vy, $html);
        }
    }

    /**
     * Every sheet carries the programme's deep link id, section and programme, so a link
     * to `#section-<section>-program-<id>` lands on the sheet it names.
     */
    public function testEverySheetBodyCarriesItsDeepLinkId(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/programy')->getBody();

        self::assertStringContainsString('<div class="sheet-body" id="section-1-program-1" data-key="1" data-pg-detail="1"', $html);
        self::assertSame(substr_count($html, 'class="sheet-body"'), preg_match_all('/<div class="sheet-body" id="section-\d+-program-(\d+)" data-key="\1"/', $html));
    }

    /**
     * A programme's row and card are links to its sheet's hash on the address they are on,
     * so without JavaScript the sheet opens on `:target`; the script intercepts them.
     */
    public function testProgrammesOpenByLinkToTheirSheet(): void
    {
        $app = $this->createApp('korbo26');
        $this->loginAs($app, 1);

        $mine = (string) $this->request($app, 'GET', '/programy/moje')->getBody();
        self::assertStringContainsString('<a class="pl-open" href="/korbo26/programy/moje#section-6-program-5" data-pg-open="5">', $mine);

        $timeline = (string) $this->request($app, 'GET', '/programy')->getBody();
        self::assertMatchesRegularExpression('~<a class="tl-card[^"]*" href="/korbo26/programy#section-1-program-1" data-key="1" style="[^"]*" data-pg-open="1" aria-label="[^"]*">~', $timeline);
        // every row and every card links to a sheet the page carries
        preg_match_all('~<a class="(?:pl-open|tl-card[^"]*)" href="/korbo26/programy#(section-\d+-program-(\d+))"[^>]* data-pg-open="(\d+)"~', $timeline, $links, PREG_SET_ORDER);
        self::assertNotEmpty($links);
        foreach ($links as [, $hash, $linked, $opened]) {
            self::assertSame($linked, $opened);
            self::assertStringContainsString('<div class="sheet-body" id="' . $hash . '"', $timeline);
        }
    }

    /**
     * The close row and the backdrop are links to `#zavrit`, a hash that names nothing, so
     * the `:target` sheet closes on the same address. The closed sheet is hidden by CSS,
     * never by an aria-hidden in the markup.
     */
    public function testTheSheetClosesByLinkAndIsNotHiddenByMarkup(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/programy/prehled')->getBody();

        self::assertStringContainsString('<a class="sheet-close" href="/korbo26/programy/prehled#zavrit" data-pg-close>✕ Zavřít</a>', $html);
        self::assertStringContainsString('<a class="sheet-backdrop" href="/korbo26/programy/prehled#zavrit" data-pg-close aria-hidden="true" tabindex="-1"></a>', $html);
        self::assertStringContainsString('<div class="sheet" data-key="sheet" data-pg-sheet>', $html);
        self::assertStringNotContainsString('id="zavrit"', $html);
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
        $stub = new \App\Program\LocalProgramProvider(dirname(__DIR__, 2) . '/events/obrok19/fixtures', $this->stubState);
        $failing = new class ($stub) implements ProgramProviderInterface {
            public function __construct(private ProgramProviderInterface $inner) {}

            public function getPrograms(): array
            {
                return $this->inner->getPrograms();
            }

            public function getSections(): array
            {
                return $this->inner->getSections();
            }

            public function getPlaces(): array
            {
                return [];
            }

            public function getRegistrationWindow(): \App\Program\RegistrationWindow
            {
                return $this->inner->getRegistrationWindow();
            }

            public function getParticipant(string $sessionToken): \App\Program\Participant
            {
                throw new \App\Program\ProgramUnavailableException('down');
            }

            public function getParticipantIdsForProgramme(int $programmeId): array
            {
                return [];
            }

            public function startPairing(string $deviceId): \App\Program\Pairing
            {
                return $this->inner->startPairing($deviceId);
            }

            public function pollPairing(string $deviceCode): \App\Program\PairingPoll
            {
                return $this->inner->pollPairing($deviceCode);
            }

            public function register(string $sessionToken, int $programmeId): void
            {
                $this->inner->register($sessionToken, $programmeId);
            }

            public function unregister(string $sessionToken, int $programmeId): void
            {
                $this->inner->unregister($sessionToken, $programmeId);
            }

            public function revokeSession(string $sessionToken): void
            {
                $this->inner->revokeSession($sessionToken);
            }
        };
        $app = $this->createApp('obrok19', [ProgramProviderInterface::class => $failing]);
        $this->loginAs($app);
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        self::assertStringContainsString('Osobní program se nepodařilo načíst.', $html);
        self::assertStringNotContainsString('is-dimmed', $html);
        self::assertStringNotContainsString('Zatím nemáš zapsaný žádný program.', $html);
    }

    /** U-M5: with no programme list the outage notice speaks alone. */
    public function testAnOutageShowsNoEmptyLine(): void
    {
        $down = new \App\Program\ProgramUnavailableException('down');
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

    /**
     * A Harmonogram chip links `?den=<YYYYMMDD>`, which is how a reader without JavaScript
     * pages the timeline: the page of that date is the active one, and its chip current.
     */
    public function testDenPicksTheTimelinePage(): void
    {
        $app = $this->createApp('korbo26');
        $keys = self::pageKeys((string) $this->request($app, 'GET', '/programy')->getBody());
        self::assertGreaterThan(1, count($keys), 'korbo26 has more than one day');
        $second = $keys[1];
        self::assertSame(1, preg_match('/^page-(\d{8})$/', $second, $date));

        $html = (string) $this->request($app, 'GET', '/programy?den=' . $date[1])->getBody();

        self::assertSame($second, self::activePage($html));
        self::assertMatchesRegularExpression(
            '~<a class="day-chip" href="/korbo26/programy\?den=' . $date[1] . '" data-key="' . $second . '" data-pg-page="' . $second . '" data-pg-kind="timeline" aria-current="true" data-morph-keep="aria-current">~',
            $html,
        );
        self::assertSame(1, substr_count($html, 'data-pg-kind="timeline" aria-current="true"'), 'one chip is current');
        // every timeline chip links its own date
        preg_match_all('~<a class="day-chip" href="/korbo26/programy\?den=(\d{8})" data-key="page-(\d{8})"~', $html, $chips, PREG_SET_ORDER);
        self::assertCount(count($keys), $chips);
        foreach ($chips as [, $linked, $keyed]) {
            self::assertSame($keyed, $linked);
        }
    }

    /** A `den` that names no page, or is not a date at all, leaves today's rule in charge. */
    public function testAnUnknownDenFallsBackToTodaysRule(): void
    {
        $app = $this->createApp('korbo26');
        $default = self::activePage((string) $this->request($app, 'GET', '/programy')->getBody());
        self::assertNotNull($default);

        foreach (['?den=19000101', '?den%5B%5D=x', '?den=page-20260712', '?den=', '?den=2026071'] as $query) {
            $response = $this->request($app, 'GET', '/programy' . $query);
            self::assertSame(200, $response->getStatusCode(), $query);
            self::assertSame($default, self::activePage((string) $response->getBody()), $query);
        }
    }

    /**
     * Přehled's and Moje's days are both in the document, so each list's days carry ids of
     * their own (`den-`, `moje-den-`), and each strip's chips link to its own on this address.
     */
    public function testListDaysCarryTheirOwnIds(): void
    {
        $app = $this->createApp('korbo26');
        $this->loginAs($app, 1);

        $html = (string) $this->request($app, 'GET', '/programy/moje')->getBody();

        self::assertSame(1, preg_match('~<div class="view view-overview"[^>]*>.*?<section class="pl-day" data-key="day-(\d{8})" id="den-(\d{8})"~s', $html, $overview));
        self::assertSame($overview[2], $overview[1]);
        self::assertSame(1, preg_match('~<div class="view view-list"[^>]*>.*?<section class="pl-day" data-key="day-(\d{8})" id="moje-den-(\d{8})"~s', $html, $mine));
        self::assertSame($mine[2], $mine[1]);
        self::assertStringContainsString(
            '<a class="day-chip" href="/korbo26/programy/moje#moje-den-' . $mine[1] . '" data-key="day-' . $mine[1] . '" data-pg-page="day-' . $mine[1] . '" data-pg-kind="list"',
            $html,
        );
        self::assertStringContainsString(
            '<a class="day-chip" href="/korbo26/programy/moje#den-' . $overview[1] . '" data-key="day-' . $overview[1] . '" data-pg-page="day-' . $overview[1] . '" data-pg-kind="overview"',
            $html,
        );
        // every list chip names a day the page carries
        preg_match_all('~<a class="day-chip" href="/korbo26/programy/moje#((?:moje-)?den-\d{8})"~', $html, $links);
        self::assertNotEmpty($links[1]);
        foreach ($links[1] as $id) {
            self::assertMatchesRegularExpression('~<section class="pl-day" data-key="day-\d{8}" id="' . $id . '"~', $html);
        }
    }

    /**
     * Volejbal (korbo26 programme 1) has two demo photos: its sheet opens with the gallery,
     * before the name, each photo lazy and kept `is-loaded` over a morph, the strip a named
     * focusable group and a dot per photo, the first one active.
     */
    public function testASheetWithPhotosOpensWithItsGallery(): void
    {
        $body = self::sheetBody((string) $this->request($this->createApp('korbo26'), 'GET', '/programy')->getBody(), 1);

        $gallery = strpos($body, '<div class="gallery" data-key="gallery" data-gallery>');
        self::assertNotFalse($gallery, 'programme 1 has a gallery');
        self::assertLessThan(strpos($body, '<div class="sheet-head">'), $gallery, 'the gallery comes before the name');
        self::assertSame(2, substr_count($body, 'class="gallery-frame"'));
        preg_match_all('~<img class="gallery-photo" src="([^"]+)" alt="[^"]*" ([^>]*)>~', $body, $photos, PREG_SET_ORDER);
        self::assertCount(2, $photos);
        foreach ($photos as [, $src, $rest]) {
            self::assertStringStartsWith('/events/korbo26/demo/', $src);
            self::assertSame('loading="lazy" decoding="async" crossorigin="anonymous" data-photo data-morph-keep="class"', $rest);
        }
        self::assertStringContainsString('data-gallery-strip role="group" tabindex="0" aria-label="Fotky"', $body);
        self::assertSame(2, substr_count($body, 'class="gallery-dot"'));
        // final review F5: a frame and its dot are keyed by the photo, not by its position
        preg_match_all('~<div class="gallery-frame" data-key="photo-([0-9a-f]{12})">.*?<img class="gallery-photo" src="([^"]+)"~', $body, $frames, PREG_SET_ORDER);
        self::assertCount(2, $frames);
        foreach ($frames as [, $key, $src]) {
            self::assertSame(Sheet::imageKey($src), $key, $src);
        }
        self::assertNotSame($frames[0][1], $frames[1][1]);
        self::assertStringContainsString('<span class="gallery-dot" data-key="dot-' . $frames[0][1] . '" data-active data-morph-keep="data-active"></span>', $body);
        self::assertStringContainsString('<span class="gallery-dot" data-key="dot-' . $frames[1][1] . '" data-morph-keep="data-active"></span>', $body);
    }

    /** One photo (korbo26 programme 11): a frame, but nothing to swipe through, so no dots and no group. */
    public function testOnePhotoHasNoDotsAndNoGroup(): void
    {
        $body = self::sheetBody((string) $this->request($this->createApp('korbo26'), 'GET', '/programy')->getBody(), 11);

        self::assertSame(1, substr_count($body, 'class="gallery-frame"'));
        self::assertStringNotContainsString('gallery-dots', $body);
        self::assertStringNotContainsString('aria-label="Fotky"', $body);
    }

    /** A programme without photos (korbo26 programme 2) has no gallery: its sheet starts with its name. */
    public function testAProgrammeWithoutPhotosStartsWithItsTitle(): void
    {
        $body = self::sheetBody((string) $this->request($this->createApp('korbo26'), 'GET', '/programy')->getBody(), 2);

        self::assertStringNotContainsString('gallery', $body);
        self::assertStringStartsWith('<div class="sheet-head">', ltrim($body));
    }

    /**
     * Each frame holds the event's logo under the photo, the placeholder until it loads or
     * when it fails: korbo26's one `mainLogo`, obrok27's light and dark pair, and nothing for
     * miquik26, which declares no logo.
     */
    public function testThePlaceholderIsTheEventsLogo(): void
    {
        $korbo = self::sheetBody((string) $this->request($this->createApp('korbo26'), 'GET', '/programy')->getBody(), 1);
        self::assertMatchesRegularExpression('~<div class="gallery-frame" data-key="photo-[0-9a-f]{12}"><img class="photo-mark" src="events/korbo26/logo_korbo_1272.png" alt="" loading="lazy" decoding="async">~', $korbo);

        $obrok = self::sheetBody((string) $this->request($this->createApp('obrok27'), 'GET', '/programy')->getBody(), 14);
        self::assertStringContainsString('<img class="photo-mark mode-light-only" src="events/obrok27/O27_logo_d.svg" alt="" loading="lazy" decoding="async">', $obrok);
        self::assertStringContainsString('<img class="photo-mark mode-dark-only" src="events/obrok27/O27_logo_w.svg" alt="" loading="lazy" decoding="async">', $obrok);
        self::assertSame(3, substr_count($obrok, 'class="gallery-frame"'));
        self::assertSame(3, substr_count($obrok, 'photo-mark mode-light-only'), 'every frame has its placeholder');

        $miquik = self::sheetBody((string) $this->request($this->createApp('miquik26'), 'GET', '/programy')->getBody(), 1);
        self::assertSame(1, substr_count($miquik, 'class="gallery-frame"'));
        self::assertStringNotContainsString('photo-mark', $miquik);
    }

    /** The gallery sits above the name and leaves the logged-out footer's login link as it was. */
    public function testTheGalleryLeavesTheLoginLinkAlone(): void
    {
        $body = self::sheetBody((string) $this->request($this->createApp('korbo26'), 'GET', '/programy')->getBody(), 1);

        self::assertStringContainsString('<div class="gallery" data-key="gallery" data-gallery>', $body);
        self::assertSame(1, substr_count($body, 'profil?program=1"'));
        self::assertStringContainsString('<a class="btn" href="/korbo26/profil?program=1"', $body);
    }

    /**
     * Programme 1 is at Brána, inside map-places' plan bounds: its Kde is a pin to Plán on
     * that place, the query for the marker, the hash for the scroll.
     */
    public function testAPlaceOnThePlanGetsAPinToThePlan(): void
    {
        $body = self::sheetBody($this->mapPlacesPrograms(), 1);

        self::assertStringContainsString('<dt>Kde</dt><dd><a class="place-pin" href="/map-places/mapa/plan?misto=1#misto-1" data-deep-link><i class="fas fa-map-marker-alt" aria-hidden="true"></i> Brána</a></dd>', $body);
    }

    /** Programme 3 is at Daleko, off the plan: Google's map shows it, so the pin goes to Mapa. */
    public function testAPlaceOffThePlanGetsAPinToTheMap(): void
    {
        $body = self::sheetBody($this->mapPlacesPrograms(), 3);

        self::assertStringContainsString('<a class="place-pin" href="/map-places/mapa?misto=3#misto-3" data-deep-link>', $body);
    }

    /** Programme 2 is at Celý tábor, which has no GPS: plain text. Programme 4 names no place and no location: no Kde. */
    public function testAPlaceWithoutGpsOrWithoutAPlaceStaysText(): void
    {
        $html = $this->mapPlacesPrograms();

        $whole = self::sheetBody($html, 2);
        self::assertStringContainsString('<dt>Kde</dt><dd>Celý tábor</dd>', $whole);
        self::assertStringNotContainsString('place-pin', $whole);
        self::assertStringNotContainsString('<dt>Kde</dt>', self::sheetBody($html, 4));
    }

    /** A plan-only event cannot show a place off its plan: Daleko stays text, Brána keeps its pin to Plán. */
    public function testAPlanOnlyEventLeavesAnOffPlanPlaceAsText(): void
    {
        $html = $this->mapPlacesPrograms('map-places-plan');

        $far = self::sheetBody($html, 3);
        self::assertStringContainsString('<dd>Daleko</dd>', $far);
        self::assertStringNotContainsString('place-pin', $far);
        self::assertStringContainsString('<a class="place-pin" href="/map-places-plan/mapa/plan?misto=1#misto-1" data-deep-link>', self::sheetBody($html, 1));
    }

    /** korbo26 has no Místa: no pin anywhere. */
    public function testEventsWithoutAMapHaveNoPins(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/programy')->getBody();

        self::assertStringContainsString('<dt>Kde</dt>', $html);
        self::assertStringNotContainsString('place-pin', $html);
    }

    /** Logged out, the pin sits in Kde and the footer's login link stays as it was. */
    public function testThePinLeavesTheLoginLinkAlone(): void
    {
        $body = self::sheetBody($this->mapPlacesPrograms(), 1);

        self::assertSame(1, preg_match('~<dd><a class="place-pin" [^>]*>.*?</a></dd>~s', $body), 'the pin is inside Kde');
        self::assertSame(1, substr_count($body, 'profil?program=1"'));
        self::assertMatchesRegularExpression('~<div class="sheet-footer"><a class="btn" href="/map-places/profil\?program=1" data-key="unpaired">~', $body);
    }

    /** A provider whose places cannot be read gives no pins and says nothing about it. */
    public function testAProviderWithoutPlacesGivesNoPins(): void
    {
        $local = new \App\Program\LocalProgramProvider(dirname(__DIR__) . '/fixtures/events/map-places/fixtures', $this->stubState);
        $provider = new class ($local) extends DelegatingProvider {
            public function getPlaces(): array
            {
                throw new \App\Program\ProgramUnavailableException('down');
            }
        };
        $response = $this->request($this->createApp('map-places', [ProgramProviderInterface::class => $provider], fixtureEvent: true), 'GET', '/programy');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('<dt>Kde</dt><dd>Brána</dd>', $html);
        self::assertStringNotContainsString('place-pin', $html);
        self::assertStringNotContainsString('nepodařilo načíst', $html);
    }

    /** The Program page of the map-places fixture event (or its plan-only twin), logged out. */
    private function mapPlacesPrograms(string $slug = 'map-places'): string
    {
        $response = $this->request($this->createApp($slug, fixtureEvent: true), 'GET', '/programy');
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** The inside of programme $id's `.sheet-body`, up to the next body or the close row. */
    private static function sheetBody(string $html, int $id): string
    {
        self::assertSame(
            1,
            preg_match('~<div class="sheet-body"[^>]* data-pg-detail="' . $id . '"[^>]*>(.*?)(?=<div class="sheet-body"|<a class="sheet-close")~s', $html, $m),
            'the page carries programme ' . $id . "'s sheet",
        );

        return $m[1];
    }

    /** @return list<string> the timeline pages' keys, in order */
    private static function pageKeys(string $html): array
    {
        preg_match_all('~<section class="tl-page[^"]*" data-morph-keep="class" data-pg-panel="timeline" data-key="([^"]+)"~', $html, $m);

        return $m[1];
    }

    /** The key of the timeline page the server marked active, null for none. */
    private static function activePage(string $html): ?string
    {
        preg_match_all('~<section class="tl-page is-active" data-morph-keep="class" data-pg-panel="timeline" data-key="([^"]+)"~', $html, $m);
        self::assertLessThanOrEqual(1, count($m[1]), 'at most one page is active');

        return $m[1][0] ?? null;
    }
}
