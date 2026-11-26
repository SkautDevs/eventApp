<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Program screen without JavaScript, on obrok19 and obrok27: each view pill is a link
 * to its view's address, a programme's row or card is a link to its sheet's hash, the
 * sheet opens on `:target`, and its close row is a link to `#zavrit`, which names nothing.
 * A Harmonogram day chip links `?den=<YYYYMMDD>`, a list's chip its day's id on the address,
 * and the zoom pair, which only the script drives, is hidden.
 * Moje and the sheet's register and unregister forms need a paired reader: those cases run
 * on korbo26, whose local source keeps registrations in the stub state, put back to the
 * fixtures before every test (resetStubState()), and pair the script-off way. The
 * gallery and thumbnail cases run on korbo26 too, whose demo photos the sheet and the rows
 * show without a script.
 */
#[Group('browser')]
final class NoJsProgramTest extends NoJsTestCase
{
    /** Each view's address under the event, in the pill's order. */
    private const array VIEWS = ['overview' => '/programy/prehled', 'timeline' => '/programy', 'list' => '/programy/moje'];

    /** Where each view's programme links are: Přehled's rows, the timeline's cards on the page on show. */
    private const array OPENERS = [
        'overview' => '#pg-view-overview a.pl-open',
        'timeline' => '#pg-view-timeline .tl-page.is-active a.tl-card',
    ];

    /** Volejbal on korbo26: not the fixture participant's, not preregistered. */
    private const string VOLEJBAL = '1';

    protected function setUp(): void
    {
        parent::setUp();
        self::resetStubState();
    }

    /** @return array<string, array{string}> */
    public static function events(): array
    {
        return ['obrok19' => ['obrok19'], 'obrok27' => ['obrok27']];
    }

    #[DataProvider('events')]
    public function testEveryViewOpensByItsPill(string $slug): void
    {
        self::visit('/' . $slug . '/programy');

        foreach (self::VIEWS as $view => $path) {
            self::follow('.tabs-tab[data-pg-view="' . $view . '"]');

            self::assertSame('/' . $slug . $path, self::path(), $slug . ': the ' . $view . ' pill is a link to its address');
            self::assertSame($view, self::attribute('.pg', 'data-view'), $slug . ': the page paints the view it names');
            self::assertSame('page', self::attribute('.tabs-tab[data-pg-view="' . $view . '"]', 'aria-current'), $slug . ': ' . $view);
            foreach (array_keys(self::VIEWS) as $other) {
                self::assertSame($other === $view, self::displayed('#pg-view-' . $other), $slug . ' on ' . $view . ': the ' . $other . ' view');
            }
        }
    }

    #[DataProvider('events')]
    public function testAProgrammeOpensFromEachViewAndCloses(string $slug): void
    {
        foreach (self::OPENERS as $view => $openers) {
            $address = '/' . $slug . self::VIEWS[$view];
            self::visit($address);
            $shown = array_values(array_filter(
                self::$browser->findElements(WebDriverBy::cssSelector($openers)),
                static fn($candidate): bool => $candidate->isDisplayed(),
            ));
            self::assertNotEmpty($shown, $slug . ': ' . $view . ' shows a programme');
            $opener = $shown[0];
            if ($view === 'overview') {
                // A row down the list, so the page is scrolled when the sheet opens. Bringing
                // a row into view puts it at the bottom edge, under the docked bars, so the one
                // brought into view is four further on and the row followed sits clear above
                // them. (The timeline's first card stays: scrolled sideways, a card slides under
                // the stage column.)
                $index = max(min(16, count($shown) - 5), 0);
                $opener = $shown[$index];
                self::$browser->getWebDriver()->action()->moveToElement($shown[min($index + 4, count($shown) - 1)])->perform();
                self::assertGreaterThan(0, self::scrollY(), $slug . ': the list is scrolled to the row');
            }
            $scrolled = self::scrollY();
            // WebDriver gives the resolved URL: its path and hash
            $url = (string) $opener->getAttribute('href');
            $href = parse_url($url, PHP_URL_PATH) . '#' . parse_url($url, PHP_URL_FRAGMENT);
            self::assertMatchesRegularExpression('~^' . preg_quote($address, '~') . '#section-\d+-program-\d+$~', $href, $slug . ': ' . $view);

            self::follow($openers . '[data-pg-open="' . $opener->getAttribute('data-pg-open') . '"]');

            self::assertSame($href, self::path(), $slug . ': ' . $view . ': the link goes to its sheet');
            self::assertTrue(self::displayed('.sheet-body:target'), $slug . ': ' . $view . ': the sheet it names');
            self::assertSame(1, self::displayedBodies(), $slug . ': ' . $view . ': one sheet body on show');
            self::assertTrue(self::displayed('.sheet-card'), $slug . ': ' . $view . ': the sheet card');
            self::assertSame($scrolled, self::scrollY(), $slug . ': ' . $view . ': opening the sheet scrolls nothing');

            self::follow('.sheet-close');

            self::assertSame($address . '#zavrit', self::path(), $slug . ': ' . $view . ': the close row stays on the address');
            self::assertSame(0, self::displayedBodies(), $slug . ': ' . $view . ': the sheet closed');
            self::assertFalse(self::displayed('.sheet-card'), $slug . ': ' . $view . ': the sheet card');
            self::assertSame($scrolled, self::scrollY(), $slug . ': ' . $view . ': closing the sheet scrolls nothing');
        }
    }

    /** A tap on the dimmed screen above the sheet closes it too: the backdrop is the same link to `#zavrit`. */
    #[DataProvider('events')]
    public function testTheBackdropClosesTheSheet(string $slug): void
    {
        $address = '/' . $slug . self::VIEWS['overview'];
        self::visit($address);
        self::follow(self::OPENERS['overview']);
        self::assertTrue(self::displayed('.sheet-card'), $slug . ': the sheet opened');

        // near the top edge, above the card, which sits on the bottom and covers the middle when tall
        $backdrop = self::$browser->findElement(WebDriverBy::cssSelector('.sheet-backdrop'));
        self::$browser->getWebDriver()->action()->moveToElement($backdrop, 0, 12 - intdiv($backdrop->getSize()->getHeight(), 2))->click()->perform();
        self::$browser->wait(10, 100)->until(
            static fn(): bool => str_ends_with(self::path(), '#zavrit'),
            'Timed out waiting for the backdrop to go to #zavrit',
        );

        self::assertSame($address . '#zavrit', self::path(), $slug . ': the backdrop stays on the address');
        self::assertSame(0, self::displayedBodies(), $slug . ': the sheet closed');
        self::assertFalse(self::displayed('.sheet-card'), $slug . ': the sheet card');
    }

    /** A Harmonogram chip is a link to `?den=<YYYYMMDD>`, whose page the server makes the active one. */
    #[DataProvider('events')]
    public function testAHarmonogramDayOpensByItsChip(string $slug): void
    {
        self::visit('/' . $slug . '/programy');
        $date = self::timelineDate(1);

        self::follow('.day-chip[data-pg-kind="timeline"][data-pg-page="page-' . $date . '"]');

        self::assertSame('/' . $slug . '/programy?den=' . $date, self::path(), $slug . ': the chip links its day');
        self::assertTrue(self::displayed('.tl-page[data-key="page-' . $date . '"]'), $slug . ': the chip\'s page is on show');
        self::assertFalse(self::displayed('.tl-page[data-key="page-' . self::timelineDate(0) . '"]'), $slug . ': the first page is not');
        self::assertSame('true', self::attribute('.day-chip[data-pg-kind="timeline"][data-pg-page="page-' . $date . '"]', 'aria-current'), $slug);
    }

    /** A list chip is a link to its day's id on this address, which docks the day's heading under the strip. */
    #[DataProvider('events')]
    public function testAListDayScrollsByItsChip(string $slug): void
    {
        self::visit('/' . $slug . '/programy/prehled');
        $chips = self::$browser->findElements(WebDriverBy::cssSelector('.day-chip[data-pg-kind="overview"]'));
        self::assertGreaterThan(1, count($chips), $slug . ': more than one day');
        $key = (string) end($chips)->getAttribute('data-pg-page');
        self::assertSame(1, preg_match('/^day-(\d{8})$/', $key, $date));

        self::follow('.day-chip[data-pg-kind="overview"][data-pg-page="' . $key . '"]');

        self::assertSame('/' . $slug . '/programy/prehled#den-' . $date[1], self::path(), $slug . ': the chip links its day');
        // the day's top, against the window's scroll and height: docked under the app bar
        // and the strip (52 + 48 px, no inset in this Chrome), so its heading is in view
        $top = self::$browser->findElement(WebDriverBy::id('den-' . $date[1]))->getLocation()->getY() - self::scrollY();
        $heading = self::$browser->findElement(WebDriverBy::cssSelector('#den-' . $date[1] . ' .pl-head'));
        self::assertGreaterThan(0, self::scrollY(), $slug . ': the window scrolled to the day');
        self::assertEqualsWithDelta(100, $top, 1.5, $slug . ': the day docks under the strip');
        self::assertLessThan(self::viewportHeight(), $top + $heading->getSize()->getHeight(), $slug . ': its heading is in view');
    }

    /**
     * The sheet's links stay on the address they are on, query included: closing a sheet
     * opened on `?den=<second day>` keeps that day (Review Focus 5).
     */
    #[DataProvider('events')]
    public function testClosingASheetKeepsTheDay(string $slug): void
    {
        self::visit('/' . $slug . '/programy');
        $date = self::timelineDate(1);
        $address = '/' . $slug . '/programy?den=' . $date;
        self::follow('.day-chip[data-pg-kind="timeline"][data-pg-page="page-' . $date . '"]');
        $card = array_values(array_filter(
            self::$browser->findElements(WebDriverBy::cssSelector(self::OPENERS['timeline'])),
            static fn($candidate): bool => $candidate->isDisplayed(),
        ))[0] ?? null;
        self::assertNotNull($card, $slug . ': the day shows a programme');
        $id = (string) $card->getAttribute('data-pg-open');

        self::follow(self::OPENERS['timeline'] . '[data-pg-open="' . $id . '"]');

        self::assertMatchesRegularExpression('~^' . preg_quote($address, '~') . '#section-\d+-program-' . $id . '$~', self::path(), $slug . ': the card stays on the day');
        self::assertTrue(self::displayed('.sheet-body:target'), $slug . ': the sheet opened');

        self::follow('.sheet-close');

        self::assertSame($address . '#zavrit', self::path(), $slug . ': the close row stays on the day');
        self::assertSame(0, self::displayedBodies(), $slug . ': the sheet closed');
        self::assertTrue(self::displayed('.tl-page[data-key="page-' . $date . '"]'), $slug . ': the day is still on show');
    }

    /**
     * Moje for a reader paired without a script: its pill opens it, a registered
     * programme's row is a link to its sheet, which opens on `:target` with the unregister
     * button, and the close row stays on Moje.
     */
    public function testMojeOpensByItsPillAndARowOpensItsSheet(): void
    {
        self::pairWithoutScript('korbo26');
        self::visit('/korbo26/programy');

        self::follow('.tabs-tab[data-pg-view="list"]');

        self::assertSame('/korbo26/programy/moje', self::path(), 'the Moje pill is a link to its address');
        self::assertSame('list', self::attribute('.pg', 'data-view'));
        self::assertTrue(self::displayed('#pg-view-list'), 'Moje is on show');
        $rows = array_values(array_filter(
            self::$browser->findElements(WebDriverBy::cssSelector('#pg-view-list a.pl-open')),
            static fn($row): bool => $row->isDisplayed(),
        ));
        self::assertNotEmpty($rows, 'Moje lists the fixture participant\'s programmes');
        $id = (string) $rows[0]->getAttribute('data-pg-open');

        self::follow('#pg-view-list a.pl-open[data-pg-open="' . $id . '"]');

        self::assertMatchesRegularExpression('~^/korbo26/programy/moje#section-\d+-program-' . $id . '$~', self::path(), 'the row goes to its sheet on Moje');
        self::assertTrue(self::displayed('.sheet-body:target'), 'the sheet it names');
        self::assertSame($id, self::attribute('.sheet-body:target', 'data-pg-detail'));
        self::assertSame(1, self::displayedBodies(), 'one sheet body on show');
        self::assertSame('Zrušit zápis', self::text('.sheet-body:target .sheet-footer button'), 'a programme of Moje is registered');

        self::follow('.sheet-close');

        self::assertSame('/korbo26/programy/moje#zavrit', self::path(), 'the close row stays on Moje');
        self::assertSame(0, self::displayedBodies(), 'the sheet closed');
        self::assertTrue(self::displayed('#pg-view-list'), 'Moje is still on show');
    }

    /**
     * The sheet's register and unregister are plain form POSTs whose redirect lands on the
     * programme's deep link on the view they were sent from (the form's `pohled`), so the
     * sheet is open again under the toast. The unregister's question is the script's: without
     * one the first tap posts.
     */
    public function testRegisterAndUnregisterFromASheetLandWithTheSheetOpen(): void
    {
        self::pairWithoutScript('korbo26');
        self::visit('/korbo26/programy/prehled');
        self::follow('#pg-view-overview a.pl-open[data-pg-open="' . self::VOLEJBAL . '"]');
        self::assertSame('Zapsat program', self::text('.sheet-body:target .sheet-footer button'));

        self::follow('.sheet-body:target .sheet-footer button');

        self::assertSame('/korbo26/programy/prehled#section-1-program-1', self::path(), 'the register lands on the sheet, on Přehled');
        self::assertTrue(self::displayed('.sheet-body:target'), 'the sheet is open again');
        self::assertSame(self::VOLEJBAL, self::attribute('.sheet-body:target', 'data-pg-detail'));
        self::assertSame('Zrušit zápis', self::text('.sheet-body:target .sheet-footer button'));
        self::assertSame('Zapsáno: Volejbal', self::text('[data-toast] strong'));

        // one tap: without a script nothing asks first, the form posts
        self::follow('.sheet-body:target .sheet-footer button');

        self::assertSame('/korbo26/programy/prehled#section-1-program-1', self::path(), 'the unregister lands on the sheet too');
        self::assertTrue(self::displayed('.sheet-body:target'), 'the sheet is open again');
        self::assertSame('Zápis zrušen: Volejbal', self::text('[data-toast] strong'));
        self::assertSame('Zapsat program', self::text('.sheet-body:target .sheet-footer button'));
    }

    /**
     * Back after a register without a script: the browser walks back to the entry before
     * the POST, an ordinary page of the Program screen, not an error or a resend. Replacing
     * the entry instead is the script's (Plan call 15).
     */
    public function testRegisterThenBackKeepsTodaysBehaviour(): void
    {
        self::pairWithoutScript('korbo26');
        self::visit('/korbo26/programy');
        self::follow(self::OPENERS['timeline'] . '[data-pg-open="' . self::VOLEJBAL . '"]');
        self::follow('.sheet-body:target .sheet-footer button');
        self::assertSame('Zapsáno: Volejbal', self::text('[data-toast] strong'));

        self::$browser->back();
        // a WebDriver command that waits for the page Back loads
        self::$browser->findElement(WebDriverBy::cssSelector('html'));

        self::assertStringStartsWith('/korbo26/programy', self::path(), 'Back stays on the Program screen');
        self::assertTrue(self::present('main > section.screen[data-screen="/korbo26/programy"]'), 'the Program screen');
        self::assertTrue(self::present('.pg[data-pg-root]'), 'a Program page, not a broken one');
        self::assertFalse(self::present('.error-page'), 'no error page');
    }

    /**
     * Without a script nothing marks a photo loaded, and none needs to be: the `:target`
     * sheet shows its photo over the placeholder, and the dots, which only the script
     * moves, are hidden (korbo26's Volejbal, two demo photos).
     */
    public function testAGalleryShowsItsPhotos(): void
    {
        self::visit('/korbo26/programy');
        $id = (string) self::attribute('.sheet-body[data-pg-detail="' . self::VOLEJBAL . '"]', 'id');
        self::visit('/korbo26/programy#' . $id);

        self::assertTrue(self::displayed('.sheet-body:target .gallery-photo'), 'the photo is shown');
        $photo = self::$browser->findElement(WebDriverBy::cssSelector('.sheet-body:target .gallery-photo'));
        self::$browser->wait(5, 100)->until(
            static fn(): bool => (int) $photo->getDomProperty('naturalWidth') > 0,
            'the photo did not load',
        );
        self::assertFalse(self::displayed('.sheet-body:target .gallery-dots'), 'no dots without a script');
    }

    /**
     * A row of a programme with photos shows its thumbnail without a script: korbo26's
     * Vzduchovky in Přehled, loaded once scrolled to (it is lazy).
     */
    public function testARowShowsItsThumbnail(): void
    {
        self::visit('/korbo26/programy/prehled');
        $css = '#pg-view-overview .pl-item[data-key="13"] .pl-thumb';
        $thumb = self::$browser->findElement(WebDriverBy::cssSelector($css));
        $thumb->getLocationOnScreenOnceScrolledIntoView();

        self::assertTrue(self::displayed($css), 'the thumbnail is shown');
        self::$browser->wait(5, 100)->until(
            static fn(): bool => (int) $thumb->getDomProperty('naturalWidth') > 0,
            'the thumbnail did not load',
        );
    }

    /**
     * obrok27's programme 11 is at Plaza, on the plan: the pin in its `:target` sheet opens
     * Plán on that place, the marker shown and scrolled to, and Back returns to the sheet.
     */
    public function testThePinOpensThePlanOnThePlaceAndBackReturnsToTheSheet(): void
    {
        self::visit('/obrok27/programy/prehled');
        $sheet = (string) self::attribute('.sheet-body[data-pg-detail="11"]', 'id');
        self::assertNotSame('', $sheet);
        self::visit('/obrok27/programy/prehled#' . $sheet);
        self::assertTrue(self::displayed('.sheet-body:target .place-pin'), 'the sheet shows its pin');
        parse_str((string) parse_url((string) self::attribute('.sheet-body:target .place-pin', 'href'), PHP_URL_QUERY), $query);
        $place = $query['misto'] ?? null;
        self::assertIsString($place);
        self::assertMatchesRegularExpression('/^[1-9]\d*$/', $place);

        self::follow('.sheet-body:target .place-pin');

        self::assertSame('/obrok27/mapa/plan?misto=' . $place . '#misto-' . $place, self::path());
        self::assertTrue(self::displayed('#misto-' . $place), 'the place\'s marker');
        self::assertCount(1, self::$browser->findElements(WebDriverBy::cssSelector('.plan #misto-' . $place)), 'the marker is on the plan');

        self::$browser->back();

        self::assertSame('/obrok27/programy/prehled#' . $sheet, self::path());
        self::assertTrue(self::displayed('.sheet-body:target'), 'the sheet is open again');
    }

    /** Only the script stretches the hour scale, so without it the zoom pair is not there to press. */
    #[DataProvider('events')]
    public function testZoomIsHidden(string $slug): void
    {
        self::visit('/' . $slug . '/programy');

        self::assertFalse(self::displayed('.pager-zoom'), $slug);
    }

    /** The YYYYMMDD of the timeline's chip at $index, in the strip's order. */
    private static function timelineDate(int $index): string
    {
        $chips = self::$browser->findElements(WebDriverBy::cssSelector('.day-chip[data-pg-kind="timeline"]'));
        self::assertArrayHasKey($index, $chips, 'the timeline has a chip ' . $index);
        self::assertSame(1, preg_match('/^page-(\d{8})$/', (string) $chips[$index]->getAttribute('data-pg-page'), $date));

        return $date[1];
    }

    /** The window's height in CSS pixels, from CDP's layout metrics, as scrollY(). */
    private static function viewportHeight(): float
    {
        $metrics = self::devTools()->execute('Page.getLayoutMetrics');
        self::assertIsArray($metrics['cssVisualViewport'] ?? null);

        return (float) $metrics['cssVisualViewport']['clientHeight'];
    }

    /**
     * The page's vertical scroll, from CDP's layout metrics: the page's own JavaScript is
     * off, and WebDriver's element rects are measured from the document, not the viewport.
     */
    private static function scrollY(): float
    {
        $metrics = self::devTools()->execute('Page.getLayoutMetrics');
        self::assertIsArray($metrics['cssVisualViewport'] ?? null);

        return (float) $metrics['cssVisualViewport']['pageY'];
    }

    private static function displayedBodies(): int
    {
        return count(array_filter(
            self::$browser->findElements(WebDriverBy::cssSelector('.sheet-body')),
            static fn($body): bool => $body->isDisplayed(),
        ));
    }
}
