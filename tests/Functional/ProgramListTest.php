<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\CoreStylesheet;

/**
 * Přehled and Moje are one list component: one continuous scroll over the whole event.
 *
 * It used to page by day, one .pl-day visible at a time. Now every day is present and
 * visible in document order, each under its own sticky heading, and the strip of day
 * chips on top follows the scroll; a tap on a chip scrolls to its day. Přehled lists every
 * programme, Moje the participant's own. The timeline still pages, by day, through a strip
 * of the same chips.
 */
final class ProgramListTest extends AppTestCase
{
    /** obrok19 has 69 programmes, all in listed sections. */
    private const int PROGRAMMES = 69;

    /** obrok19's fixture participant 1 is registered for eight programmes across three days. */
    private const array MINE = [5, 22, 29, 34, 49, 62, 73, 84];

    private function loggedInScreen(): string
    {
        $app = $this->createApp();
        $this->pair($app);

        return (string) $this->request($app, 'GET', '/programy')->getBody();
    }

    /** The markup of one view of the screen: `overview`, `timeline` or `list`. */
    private static function view(string $html, string $kind): string
    {
        $start = strpos($html, 'id="pg-view-' . $kind . '"');
        self::assertNotFalse($start, "no {$kind} view");
        $ends = array_filter([
            strpos($html, 'id="pg-view-', $start + 1),
            strpos($html, 'data-key="tabs"', $start),
        ]);

        return substr($html, $start, min($ends) - $start);
    }

    /** @return array<int, string> the classes of every list row of the view, by programme id */
    private static function rows(string $view): array
    {
        preg_match_all('#<article class="(pl-item[^"]*)" data-key="(\d+)"#', $view, $m, PREG_SET_ORDER);
        $rows = [];
        foreach ($m as [, $classes, $id]) {
            $rows[(int) $id] = $classes;
        }

        return $rows;
    }

    /** The markup of programme $id's row in the view, from `<article` to `</article>`. */
    private static function article(string $view, int $id): string
    {
        self::assertSame(1, preg_match('#<article class="pl-item[^"]*" data-key="' . $id . '".*?</article>#s', $view, $m), "no row for programme {$id}");

        return $m[0];
    }

    public function testPrehledListsEveryProgrammeAndDimsWhatIsNotTheParticipants(): void
    {
        $overview = self::view($this->loggedInScreen(), 'overview');
        $rows = self::rows($overview);

        self::assertCount(self::PROGRAMMES, $rows);
        foreach ($rows as $id => $classes) {
            $mine = in_array($id, self::MINE, true);
            self::assertSame($mine, str_contains($classes, 'is-registered'), "programme {$id}");
            self::assertSame(!$mine, str_contains($classes, 'is-dimmed'), "programme {$id}");
        }
        // the tag is on the participant's rows and nowhere else
        self::assertSame(count(self::MINE), substr_count($overview, '<span class="pl-tag">Zapsáno</span>'));
        // every row: the start time, the name, then "do HH:MM · place"
        self::assertMatchesRegularExpression('#<p class="pl-time">\d{2}:\d{2}</p>#', $overview);
        self::assertMatchesRegularExpression('#<p class="pl-meta">do \d{2}:\d{2}( · [^<]+)?</p>#u', $overview);
    }

    public function testLoggedOutPrehledListsEveryProgrammeAndDimsNothing(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();
        $rows = self::rows(self::view($html, 'overview'));

        self::assertCount(self::PROGRAMMES, $rows);
        self::assertStringNotContainsString('is-dimmed', implode(' ', $rows));
        self::assertStringNotContainsString('is-registered', implode(' ', $rows));
        self::assertStringNotContainsString('Zapsáno<', $html);
        self::assertStringNotContainsString('pl-tag', $html);
    }

    /** Moje is the participant's own, so every row of it carries the tag and none is dimmed. */
    public function testEveryRowOfMojeIsTaggedAndNoneIsDimmed(): void
    {
        $list = self::view($this->loggedInScreen(), 'list');
        $rows = self::rows($list);

        self::assertEqualsCanonicalizing(self::MINE, array_keys($rows));
        foreach ($rows as $id => $classes) {
            self::assertSame('pl-item is-registered', $classes, "programme {$id}");
        }
        self::assertSame(count(self::MINE), substr_count($list, '<span class="pl-tag">Zapsáno</span>'));
    }

    /** The pill names the three views in their order, Harmonogram still the default. */
    public function testThePillNamesTheThreeViews(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertMatchesRegularExpression(
            '#data-pg-view="overview"[^>]*><i class="far fa-list-alt" aria-hidden="true"></i> <span class="tabs-label">Přehled</span>.*'
            . 'data-pg-view="timeline"[^>]*><i class="far fa-calendar-alt" aria-hidden="true"></i> <span class="tabs-label">Harmonogram</span>.*'
            . 'data-pg-view="list"[^>]*><i class="far fa-user" aria-hidden="true"></i> <span class="tabs-label">Moje</span>#su',
            $html,
        );
        self::assertStringContainsString('data-pg-root data-morph-keep="data-view data-step" data-pg-event="obrok19" data-view="timeline"', $html);
        self::assertMatchesRegularExpression('#aria-selected="true"[^>]*data-pg-view="timeline"#', $html);
    }

    /**
     * Each pill is a link to its view's address, so a reader without JavaScript switches
     * views too. The shown view's pill is the current page; the markup writes no tabindex,
     * so every pill is reachable without a script (the script puts the roving one on).
     */
    public function testEachPillIsALinkToItsView(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/programy')->getBody();

        foreach (['overview' => '/korbo26/programy/prehled', 'timeline' => '/korbo26/programy', 'list' => '/korbo26/programy/moje'] as $view => $href) {
            self::assertStringContainsString(
                '<a class="tabs-tab" role="tab" id="pg-tab-' . $view . '" href="' . $href . '" aria-controls="pg-view-' . $view . '"'
                . ' aria-selected="' . ($view === 'timeline' ? 'true' : 'false') . '" data-pg-view="' . $view . '"'
                . ($view === 'timeline' ? ' aria-current="page"' : '') . ' data-morph-keep="aria-selected aria-current">',
                $html,
                $view,
            );
        }
        self::assertSame(1, preg_match('#<div class="tabs" role="tablist".*?</div>#s', $html, $tablist));
        self::assertSame(1, substr_count($tablist[0], 'aria-current="page"'));
        self::assertStringNotContainsString('tabindex', $tablist[0]);
    }

    public function testListItemsCarryTheirTimesAsInstants(): void
    {
        $html = $this->loggedInScreen();

        preg_match_all('#<article class="pl-item[^"]*" data-key="\d+" data-start="([^"]+)" data-end="([^"]+)"#', $html, $m, PREG_SET_ORDER);
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
        $html = self::view($this->loggedInScreen(), 'list');

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
        foreach (['overview', 'list'] as $kind) {
            $view = self::view($html, $kind);
            self::assertGreaterThan(0, strpos($view, 'day-20190530'));
            self::assertGreaterThan((int) strpos($view, 'day-20190530'), (int) strpos($view, 'day-20190531'));
            self::assertGreaterThan((int) strpos($view, 'day-20190531'), (int) strpos($view, 'day-20190601'));
            // the strip opens on the day at the top of the scroll, not today
            self::assertMatchesRegularExpression('#data-pg-page="day-20190530" data-pg-kind="' . $kind . '" aria-current="true" data-morph-keep="aria-current">čt 30. 5.</a>#u', $html);
        }
    }

    /** Both views pick the day from a strip of chips; no arrows, no day dialog. */
    public function testBothViewsPickTheDayFromAStripOfChips(): void
    {
        $html = $this->loggedInScreen();

        foreach (['overview', 'timeline', 'list'] as $kind) {
            self::assertMatchesRegularExpression('#<div class="days" role="group" aria-label="Dny" data-pg-days="' . $kind . '">#', $html);
        }
        self::assertStringNotContainsString('pager-menu', $html);
        self::assertStringNotContainsString('data-pg-step', $html);
        self::assertStringNotContainsString('data-pg-list-top', $html);
        // exactly one chip per strip is current, and the morph leaves the reader's choice alone
        self::assertSame(3, substr_count($html, 'aria-current="true" data-morph-keep="aria-current"'));
        // the zoom pair belongs to the axis, so it stays on the timeline's row alone
        self::assertSame(1, substr_count($html, 'class="pager-zoom"'));
    }

    public function testTheChipsAreFullSizedTouchTargets(): void
    {
        $css = CoreStylesheet::read();
        self::assertMatchesRegularExpression('/\.day-chip\s*\{[^}]*min-height:\s*44px/s', $css);
        self::assertMatchesRegularExpression('/\.day-chip\s*\{[^}]*min-width:\s*44px/s', $css);
    }

    /** Every day is visible: nothing in the list is display:none any more. */
    public function testTheStylesheetNoLongerHidesADay(): void
    {
        $css = CoreStylesheet::read();

        self::assertStringNotContainsString('.tl-page, .pl-day', $css);
        self::assertStringNotContainsString('.pl-day.is-active', $css);
        // The heading docks under the strip rather than scrolling away with its day —
        // against the same line the strip itself is docked against, the notch included.
        // bandTop() in www/js/programs/daylist.js adds up the same three tokens, and the two have
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

        self::assertStringNotContainsString('class="pl-day"', self::view($html, 'list'));
        self::assertStringNotContainsString('data-pg-pager="list"', $html);
        self::assertStringContainsString('class="link-card is-highlight pair-card"', self::view($html, 'list'));
        // Přehled is everybody's
        self::assertStringContainsString('class="pl-day"', self::view($html, 'overview'));
        self::assertStringContainsString('data-pg-pager="overview"', $html);
    }

    /** Logged out, the list holds Domů's login card, which sends the reader to Profil: no form of its own. */
    public function testLoggedOutTheListLinksToProfil(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertStringContainsString('<section class="link-card is-highlight pair-card" data-key="pair"><h2>Už jsi registrovaný na Obrok 19?</h2><p>Přihlas se údaji ze své registrace, ať nepřijdeš o notifikace, výběr programů a upozornění v průběhu akce!</p><a class="btn btn-primary" href="/obrok19/profil">Přihlásit se</a></section>', self::view($html, 'list'));
        self::assertStringNotContainsString('<input type="hidden" name="return"', $html);
        self::assertStringNotContainsString('<input type="text"', $html);
    }

    /** A pairing is collected on Profil, so its start lands there whatever the form says. */
    public function testAPairingStartAlwaysLandsOnProfil(): void
    {
        $app = $this->createApp();
        foreach (['programy', 'https://evil.example', ''] as $return) {
            $response = $this->request($app, 'POST', '/profil/propojit', ['return' => $return]);

            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/obrok19/profil', $response->getHeaderLine('Location'), $return);
        }
    }

    /** After the logout the list is the logged-out one again: no days, the link back. */
    public function testAfterTheLogoutTheListIsEmptyAgain(): void
    {
        $app = $this->createApp();
        $this->pair($app);
        self::assertStringContainsString('class="pl-day"', self::view((string) $this->request($app, 'GET', '/programy')->getBody(), 'list'));

        $this->request($app, 'POST', '/profil/odpojit');
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        self::assertStringNotContainsString('class="pl-day"', self::view($html, 'list'));
        self::assertStringContainsString('class="link-card is-highlight pair-card"', self::view($html, 'list'));
    }

    /**
     * korbo26's Vzduchovky (programme 13) has three demo photos: its row carries the first
     * one's thumbnail, once, in a frame over the event's placeholder, keyed for the morph,
     * which leaves its loaded mark alone.
     */
    public function testARowWithPhotosHasOneThumbnail(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/programy/prehled')->getBody();
        $row = self::article(self::view($html, 'overview'), 13);

        self::assertStringStartsWith('<article class="pl-item has-thumb" data-key="13"', $row);
        self::assertSame(1, substr_count($row, '<span class="pl-thumb-frame" data-key="thumb" aria-hidden="true">'));
        self::assertSame(1, substr_count($row, 'class="pl-thumb"'));
        self::assertStringContainsString(
            '<img class="pl-thumb" src="/events/korbo26/demo/strelba-1-thumb.webp" alt="" loading="lazy" decoding="async" crossorigin="anonymous" data-photo data-morph-keep="class">',
            $row,
        );
        self::assertStringContainsString('class="photo-mark', $row, 'the placeholder under the thumbnail');
    }

    /** A programme without photos (korbo26's programme 2) has no frame at all. */
    public function testARowWithoutPhotosHasNoFrame(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/programy/prehled')->getBody();
        $row = self::article(self::view($html, 'overview'), 2);

        self::assertStringNotContainsString('has-thumb', $row);
        self::assertStringNotContainsString('pl-thumb', $row);
        self::assertStringNotContainsString('photo-mark', $row);
    }

    /** Moje's rows are the same component: the participant's Speed dating (programme 5) carries its thumbnail. */
    public function testMojeShowsTheThumbnailToo(): void
    {
        $app = $this->createApp('korbo26');
        $this->loginAs($app, 1);
        $html = (string) $this->request($app, 'GET', '/programy/moje')->getBody();
        $row = self::article(self::view($html, 'list'), 5);

        self::assertStringStartsWith('<article class="pl-item is-registered has-thumb" data-key="5"', $row);
        self::assertStringContainsString('<img class="pl-thumb" src="/events/korbo26/demo/seznamka-1-thumb.webp"', $row);
    }
}
