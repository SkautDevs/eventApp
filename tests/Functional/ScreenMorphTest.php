<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * A stale screen whose HTML changed is morphed into the new one, never replaced.
 *
 * The morph itself is behaviour and is proved in a browser; what a test can hold is
 * the contract it depends on — that the loader has no assignment to innerHTML left in
 * it, and that every repeated row the server renders carries the key the morph matches
 * on, so an insertion in the middle of a list moves nothing after it.
 */
final class ScreenMorphTest extends AppTestCase
{
    private function loader(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/www/app.js');
    }

    public function testTheLoaderNeverReplacesAScreensMarkup(): void
    {
        $js = $this->loader();

        // A screen's innerHTML is read — it is how one is compared with the fresh
        // response — but never written: writing it is exactly what threw away the live
        // state. The one assignment left is into the detached <template> the response
        // is parsed in, which owns no state at all.
        self::assertSame(1, substr_count($js, 'innerHTML ='));
        self::assertStringContainsString('holder.innerHTML = html;', $js);
        self::assertStringContainsString('function morphChildren(', $js);
        // a matched iframe is neither written to nor moved: both reload it
        self::assertStringContainsString("from.tagName === 'IFRAME'", $js);
        self::assertStringContainsString('function ownsIframe(', $js);
    }

    public function testEveryRepeatedRowCarriesAKey(): void
    {
        $app = $this->createApp();
        $this->pair($app);
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        // every card carries one, so a programme added to a day cannot shift the rest
        self::assertSame(
            substr_count($html, '<button type="button" class="tl-card'),
            (int) preg_match_all('/<button type="button" class="tl-card[^"]*" data-key="\d+"/', $html),
        );
        self::assertMatchesRegularExpression('/<button type="button" class="tl-card[^"]*" data-key="\d+"/', $html);
        self::assertMatchesRegularExpression('/<div class="sheet-body" data-key="\d+"/', $html);
        self::assertMatchesRegularExpression('/<article class="pl-item[^"]*" data-key="\d+"/', $html);
        self::assertMatchesRegularExpression('/<section class="tl-page[^"]*"[^>]* data-key="page-\d{8}"/', $html);
        self::assertMatchesRegularExpression('/<section class="pl-day" data-key="day-\d+"/', $html);
    }

    /** C-I1: .pg's children appear and disappear; positional matching once turned the sheet into a day panel. */
    public function testEveryChildOfTheProgramRootCarriesAKey(): void
    {
        $app = $this->createApp();
        $this->pair($app);
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        foreach (['pager-overview', 'pager-timeline', 'pager-list', 'tabs', 'sheet'] as $key) {
            self::assertStringContainsString('data-key="' . $key . '"', $html);
        }
        // the three views are matched on their ids
        foreach (['pg-view-overview', 'pg-view-timeline', 'pg-view-list'] as $id) {
            self::assertSame(1, substr_count($html, 'id="' . $id . '"'), $id);
        }
        // the two list views reuse the day keys, each inside its own view
        self::assertMatchesRegularExpression('/id="pg-view-overview".*<section class="pl-day" data-key="day-\d{8}"/s', $html);
        self::assertMatchesRegularExpression('/<div class="sheet" data-key="sheet"/', $html);
        // the loader's own handle on the dialog is looked up per use, never held
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/www/programs.js');
        self::assertStringNotContainsString("const sheet = root.querySelector('[data-pg-sheet]');", $js);
    }

    /**
     * A sheet footer's one child is keyed by what it offers. The morph never removes an
     * attribute the fresh markup lacks, so an unkeyed form matched by position across an
     * unregister → register change kept the unregister's data-confirm on "Přihlásit se".
     * Keyed, the two forms are different elements and the morph replaces one with the other.
     */
    public function testEverySheetFooterChildIsKeyedByItsState(): void
    {
        $app = $this->createApp('korbo26');
        // logged out, the link is keyed too
        self::assertStringContainsString(
            '<div class="sheet-footer"><a class="btn" href="/korbo26/profil" data-key="unpaired">',
            (string) $this->request($app, 'GET', '/programy')->getBody(),
        );
        $this->pair($app);
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        preg_match_all('#<div class="sheet-footer">(<(\w+)[^>]*>)#', $html, $children, PREG_SET_ORDER);
        self::assertNotEmpty($children);
        foreach ($children as [, $open]) {
            self::assertMatchesRegularExpression('/ data-key="(?:unpaired|before|after|none|register|unregister)"/', $open);
        }
        self::assertStringContainsString('action="/korbo26/programy/1/prihlasit" data-key="register"', $html);
        self::assertStringContainsString('action="/korbo26/programy/5/odhlasit" data-key="unregister"', $html);
        // data-confirm lives on the unregister form's button only
        self::assertSame(0, preg_match('#data-key="register"[^>]*><button[^>]*data-confirm#', $html));
    }

    /**
     * The other half of the contract: the markup says which of its own attributes the
     * server writes but does not own. Without it a background morph would put the
     * screen back on the timeline, and on the timeline's first page, because that is
     * what a fresh render defaults to.
     */
    public function testTheScreenDeclaresWhatTheServerMustNotWriteBack(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertStringContainsString('data-pg-root data-morph-keep="data-view data-step"', $html);
        self::assertMatchesRegularExpression('/<section class="tl-page[^"]*" data-morph-keep="class"/', $html);
        // the current day chip is the reader's: a morph must not put it back on the server's day
        self::assertMatchesRegularExpression('/<button type="button" class="day-chip" data-key="page-\d{8}" data-pg-page="page-\d{8}" data-pg-kind="timeline" aria-current="(?:true|false)" data-morph-keep="aria-current">/', $html);
        self::assertStringNotContainsString('data-morph-keep="class" data-pg-page', $html);
        // the now-line's state is the reader's clock's: data-now and --now-offset are
        // written by www/programs.js only, so a morph has nothing of its own to put back
        self::assertStringContainsString('data-axis-start=', $html);
        self::assertDoesNotMatchRegularExpression('/<section class="tl-page[^>]*\sdata-now[\s=>]/', $html);
        self::assertStringNotContainsString('--now-offset', $html);
        self::assertStringContainsString("from.getAttribute('data-morph-keep')", $this->loader());
    }

    /** The map's view is the reader's: a morph must not put the screen back on Google's. */
    public function testTheMapViewIsTheReaders(): void
    {
        $html = (string) $this->request($this->createApp('map-plan', fixtureEvent: true), 'GET', '/mapa', null, ['X-Screen' => '1'])->getBody();

        self::assertStringContainsString('data-view="google" data-morph-keep="data-view"', $html);
        // written by www/map.js only
        self::assertStringNotContainsString('data-map-ready', $html);
    }

    /**
     * The map is the screen the morph must be gentlest with: its iframe is the one
     * node in the app that cannot be moved or re-created without refetching Google.
     * It is a lone child of .map, so the morph's positional match finds it.
     */
    public function testTheMapsIframeIsALoneChild(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/mapa', null, ['X-Screen' => '1'])->getBody();

        self::assertSame(1, substr_count($html, '<iframe'));
        self::assertMatchesRegularExpression('~<div class="map" data-map>\s*<iframe [^>]*></iframe>\s*</div>~', $html);
    }

    /**
     * Review Focus 5: the comparison is on innerHTML, which leaves the section's own
     * attributes out — so the data timestamp is carried over explicitly, on the unchanged
     * path and on the morph path alike.
     */
    public function testARevalidationCarriesTheFreshTimestampEvenWhenNothingElseChanged(): void
    {
        $js = $this->loader();

        self::assertStringContainsString('function syncFreshness(section, fresh)', $js);
        self::assertStringContainsString("['data-fetched-at', 'data-stale']", $js);
        // the definition, the morph path and the unchanged path; a mention in a // comment
        // or a docblock line does not count
        $code = implode("\n", array_filter(
            explode("\n", $js),
            static fn (string $line): bool => preg_match('~^\s*(//|\*|/\*)~', $line) !== 1,
        ));
        self::assertSame(1, preg_match_all('/\bfunction syncFreshness\(/', $code));
        self::assertSame(1, preg_match_all('/^\s*syncFreshness\(entry\.section, fresh\.section\);/m', $code), 'the morph path');
        self::assertSame(1, preg_match_all('/^\s*syncFreshness\(entry\.section, fresh\);/m', $code), 'the unchanged path');
        // and the unchanged path drops a held older morph, or it would re-apply over this fetch
        self::assertSame(1, preg_match_all('/^\s*entry\.pending = null;\s*\n\s*syncFreshness\(entry\.section, fresh\);/m', $code), 'the unchanged path clears pending');
        self::assertStringContainsString("asset_version('app.js')", (string) file_get_contents(dirname(__DIR__, 2) . '/templates/_layout.twig'));
    }

    /** The worker serves load() from its cache and sends revalidate() to the network (Task 7). */
    public function testABackgroundRevalidationAsksTheNetwork(): void
    {
        $js = $this->loader();

        self::assertSame(1, substr_count($js, "fetch(path, {headers: {'X-Screen': '1'}, credentials: 'same-origin', cache: 'no-cache'})"), 'revalidate()');
        // load() carries no cache mode, only the deadline's abort signal
        self::assertSame(1, substr_count($js, "fetch(path, {headers: {'X-Screen': '1'}, credentials: 'same-origin', signal: controller ? controller.signal : undefined})"), 'load()');
    }

    public function testAFetchTheReaderWaitsForIsAnnounced(): void
    {
        $js = $this->loader();

        self::assertSame(1, substr_count($js, "document.dispatchEvent(new CustomEvent('screen:loading'));"));
        // the fetch settled for this navigation, or a later tap was answered from the cache
        self::assertSame(2, substr_count($js, "document.dispatchEvent(new CustomEvent('screen:loaded'));"));
    }

    /** Review Focus 4: a change reported before the screen arrived is not forgotten. */
    public function testTheWorkersMessagesMarkScreensStale(): void
    {
        $js = $this->loader();

        self::assertStringContainsString("navigator.serviceWorker.addEventListener('message', function (event) {", $js);
        self::assertStringContainsString('navigator.serviceWorker.startMessages();', $js);
        self::assertStringContainsString("data.type === 'screen-updated'", $js);
        self::assertStringContainsString("data.type === 'news-updated'", $js);
        self::assertStringContainsString("tab === 'info' || tab === 'homepage' || (tab === 'programs' && typeof data.programme === 'number')", $js);
        self::assertStringContainsString('staleOnArrival.add(data.path);', $js);
        self::assertStringContainsString('fetchedAt: staleOnArrival.delete(path) ? 0 : Date.now(),', $js);
    }

    public function testAFetchTheReaderWaitsForGivesUpAfterEightSeconds(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/www/app.js');
        self::assertStringContainsString('var LOAD_TIMEOUT = 8000;', $js);
        self::assertStringContainsString('controller.abort();', $js);
        self::assertStringContainsString("if (link.classList.contains('skip-link')) {", $js);
    }

    public function testAFreshnessChangeIsAnnounced(): void
    {
        $js = $this->loader();
        $start = strpos($js, 'function syncFreshness(section, fresh) {');
        self::assertNotFalse($start);
        $body = substr($js, $start, (int) strpos($js, "\n\t}\n", $start) - $start);

        self::assertStringContainsString("section.dispatchEvent(new CustomEvent('screen:freshness', {bubbles: true}));", $body);
        self::assertStringContainsString('if (changed) {', $body);
    }
}
