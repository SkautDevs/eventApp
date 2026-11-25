<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * A stale screen whose HTML changed is morphed into the new one, never replaced.
 *
 * The morph itself is behaviour, proved in tests/js/morph.test.js and in a browser; what
 * a test can hold here is the contract it depends on — that no script assigns innerHTML
 * but the loader's parser, and that every repeated row the server renders carries the
 * key the morph matches on, so an insertion in the middle of a list moves nothing after it.
 */
final class ScreenMorphTest extends AppTestCase
{
    private function loader(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/www/js/loader/loader.js');
    }

    private function morph(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/www/js/loader/morph.js');
    }

    public function testTheLoaderNeverReplacesAScreensMarkup(): void
    {
        // A screen's innerHTML is read — it is how one is compared with the fresh
        // response — but never written: writing it is exactly what threw away the live
        // state. The one assignment left under www/js is into the detached <template>
        // the response is parsed in, which owns no state at all. How the morph matches,
        // and that it never writes or moves an iframe, is tests/js/morph.test.js's.
        $root = dirname(__DIR__, 2) . '/www/js/';
        $assignments = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            $count = substr_count((string) file_get_contents($file->getPathname()), 'innerHTML =');
            if ($count > 0) {
                $assignments[substr($file->getPathname(), strlen($root))] = $count;
            }
        }
        self::assertSame(['loader/loader.js' => 1], $assignments);
        $js = $this->loader();
        $parse = substr($js, (int) strpos($js, 'function parse(html) {'), 200);
        self::assertStringContainsString('holder.innerHTML = html;', $parse);
    }

    public function testEveryRepeatedRowCarriesAKey(): void
    {
        $app = $this->createApp();
        $this->pair($app);
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        // every card carries one, so a programme added to a day cannot shift the rest
        self::assertSame(
            substr_count($html, '<a class="tl-card'),
            (int) preg_match_all('/<a class="tl-card[^"]*" href="[^"]*" data-key="\d+"/', $html),
        );
        self::assertMatchesRegularExpression('/<a class="tl-card[^"]*" href="[^"]*" data-key="\d+"/', $html);
        self::assertMatchesRegularExpression('/<div class="sheet-body" id="section-\d+-program-\d+" data-key="\d+"/', $html);
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
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/www/js/programs.js');
        $js = '';
        foreach (glob(dirname(__DIR__, 2) . '/www/js/programs/*.js') ?: [] as $file) {
            $js .= (string) file_get_contents($file);
        }
        self::assertStringContainsString("sheet: root.querySelector('[data-pg-sheet]'),", (string) file_get_contents(dirname(__DIR__, 2) . '/www/js/programs/sheet.js'));
        self::assertStringNotContainsString("const sheet = root.querySelector('[data-pg-sheet]');", $js);
    }

    /**
     * A sheet footer's one child is keyed by what it offers. The morph never removes an
     * attribute the fresh markup lacks, so an unkeyed form matched by position across an
     * unregister → register change kept the unregister's data-confirm on "Zapsat program".
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
        self::assertMatchesRegularExpression('/<a class="day-chip" href="[^"]*" data-key="page-\d{8}" data-pg-page="page-\d{8}" data-pg-kind="timeline" aria-current="(?:true|false)" data-morph-keep="aria-current">/', $html);
        self::assertStringNotContainsString('data-morph-keep="class" data-pg-page', $html);
        // the now-line's state is the reader's clock's: data-now and --now-offset are
        // written by www/js/programs/nowline.js only, so a morph has nothing of its own to put back
        self::assertStringContainsString('data-axis-start=', $html);
        self::assertDoesNotMatchRegularExpression('/<section class="tl-page[^>]*\sdata-now[\s=>]/', $html);
        self::assertStringNotContainsString('--now-offset', $html);
        self::assertStringContainsString("from.getAttribute('data-morph-keep')", $this->morph());
    }

    /**
     * Each Program address paints its own view first: the root's data-view and the pill
     * the reader sees selected. data-view stays in data-morph-keep, so a background
     * morph from another address never moves the reader off the view on show.
     */
    public function testEachProgramAddressPaintsItsView(): void
    {
        $app = $this->createApp('korbo26');

        foreach (['/programy' => 'timeline', '/programy/prehled' => 'overview', '/programy/moje' => 'list'] as $path => $view) {
            $html = (string) $this->request($app, 'GET', $path)->getBody();

            self::assertMatchesRegularExpression('/<div class="pg" data-pg-root [^>]*data-view="' . $view . '"/', $html, $path);
            foreach (['overview', 'timeline', 'list'] as $pill) {
                self::assertMatchesRegularExpression(
                    '/<a class="tabs-tab" role="tab" [^>]*aria-selected="' . ($pill === $view ? 'true' : 'false') . '"[^>]*data-pg-view="' . $pill . '"/',
                    $html,
                    $path . ' ' . $pill,
                );
                // the selected pill is the current page, both the reader's to a morph; no tabindex in the markup (the script puts the roving one on)
                self::assertStringContainsString(
                    $pill === $view ? 'aria-selected="true" data-pg-view="' . $pill . '" aria-current="page" data-morph-keep="aria-selected aria-current">' : 'aria-selected="false" data-pg-view="' . $pill . '" data-morph-keep="aria-selected aria-current">',
                    $html,
                    $path . ' ' . $pill,
                );
            }
        }
    }

    /** The map's view is the reader's: a morph must not put the screen back on Google's. */
    public function testTheMapViewIsTheReaders(): void
    {
        $html = (string) $this->request($this->createApp('map-plan', fixtureEvent: true), 'GET', '/mapa', null, ['X-Screen' => '1'])->getBody();

        self::assertStringContainsString('data-view="google" data-morph-keep="data-view"', $html);
        // written by www/js/map/index.js only
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
            static fn(string $line): bool => preg_match('~^\s*(//|\*|/\*)~', $line) !== 1,
        ));
        self::assertSame(1, preg_match_all('/\bfunction syncFreshness\(/', $code));
        self::assertSame(1, preg_match_all('/^\s*syncFreshness\(entry\.section, fresh\.section\);/m', $code), 'the morph path');
        self::assertSame(1, preg_match_all('/^\s*syncFreshness\(entry\.section, fresh\);/m', $code), 'the unchanged path');
        // and the unchanged path drops a held older morph, or it would re-apply over this fetch
        self::assertSame(1, preg_match_all('/^\s*entry\.pending = null;\s*\n\s*syncFreshness\(entry\.section, fresh\);/m', $code), 'the unchanged path clears pending');
        self::assertMatchesRegularExpression('~<script type="module" src="/js/[0-9a-f]{8}/shell/index\.js"></script>~', (string) $this->request($this->createApp(), 'GET', '/')->getBody());
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

        self::assertSame(1, substr_count($js, 'emitScreenLoading();'));
        // the fetch settled for this navigation, or a later tap was answered from the cache
        self::assertSame(2, substr_count($js, 'emitScreenLoaded();'));
        // both on document, where events.js dispatches them, under the names it holds
        $events = (string) file_get_contents(dirname(__DIR__, 2) . '/www/js/events.js');
        self::assertStringContainsString("SCREEN_LOADING = 'screen:loading';", $events);
        self::assertStringContainsString("SCREEN_LOADED = 'screen:loaded';", $events);
        self::assertStringContainsString('emitScreenLoading = () => document.dispatchEvent(new CustomEvent(SCREEN_LOADING));', $events);
        self::assertStringContainsString('emitScreenLoaded = () => document.dispatchEvent(new CustomEvent(SCREEN_LOADED));', $events);
    }

    /** Review Focus 4: a change reported before the screen arrived is not forgotten. */
    public function testTheWorkersMessagesMarkScreensStale(): void
    {
        $js = $this->loader();

        self::assertStringContainsString("navigator.serviceWorker.addEventListener('message', event => {", $js);
        self::assertStringContainsString('navigator.serviceWorker.startMessages();', $js);
        self::assertStringContainsString("data.type === 'screen-updated'", $js);
        self::assertStringContainsString("data.type === 'news-updated'", $js);
        self::assertStringContainsString("tab === 'info' || tab === 'homepage' || (tab === 'programs' && typeof data.programme === 'number')", $js);
        self::assertStringContainsString('staleOnArrival.add(data.path);', $js);
        self::assertStringContainsString('fetchedAt: staleOnArrival.delete(path) ? 0 : Date.now(),', $js);
    }

    public function testAFetchTheReaderWaitsForGivesUpAfterEightSeconds(): void
    {
        $js = $this->loader();
        self::assertStringContainsString('const LOAD_TIMEOUT = 8000;', $js);
        self::assertStringContainsString('controller.abort();', $js);
        self::assertStringContainsString("if (link.classList.contains('skip-link')) {", $js);
    }

    public function testAFreshnessChangeIsAnnounced(): void
    {
        $js = $this->loader();
        $start = strpos($js, 'function syncFreshness(section, fresh) {');
        self::assertNotFalse($start);
        $body = substr($js, $start, (int) strpos($js, "\n}\n", $start) - $start);

        // when it is announced, and when not, is tests/js/loader.test.js's
        self::assertStringContainsString('emitScreenFreshness(section);', $body);
        self::assertStringContainsString('if (changed) {', $body);
        self::assertStringContainsString("SCREEN_FRESHNESS = 'screen:freshness';", (string) file_get_contents(dirname(__DIR__, 2) . '/www/js/events.js'));
    }
}
