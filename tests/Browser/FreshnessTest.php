<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * Stale data online: when kissj failed and the server answered with an old copy, the
 * section carries data-stale="1" and the network banner says so. A background
 * revalidation is how a screen learns it (www/js/loader/loader.js syncFreshness()), so that is the
 * path driven here: the page's fetch is wrapped to mark the next revalidation's fragment
 * stale, as the server's stale-if-error answer does.
 */
#[Group('browser')]
final class FreshnessTest extends BrowserTestCase
{
    /** Marks every revalidation (cache: 'no-cache') answer stale while window.__stale is set. */
    private const string STALE_REVALIDATIONS = <<<'JS'
        window.__stale = true;
        window.__checks = 0;
        const original = window.fetch;
        window.fetch = function (input, init) {
            const promise = original.apply(this, arguments);
            if (!init || init.cache !== 'no-cache') {
                return promise;
            }
            return promise.then(response => response.text().then(html => {
                window.__checks += 1;
                const body = window.__stale ? html.replace(/(\sdata-fetched-at="[^"]*")/, '$1 data-stale="1"') : html;
                return new Response(body, {status: response.status, headers: response.headers});
            }));
        };
        JS;

    private const string SCREEN = '[data-screen="/korbo26/programy"]';

    private static function revalidate(): void
    {
        $before = self::script('return window.__checks;');
        self::script("navigator.serviceWorker.dispatchEvent(new MessageEvent('message', {data: {type: 'screen-updated', path: '/korbo26/programy'}}));");
        self::waitFor('return window.__checks > arguments[0];', [$before]);
    }

    private static function banner(): ?string
    {
        return self::script('const b = document.querySelector("[data-netstate]"); return b.hidden ? null : b.textContent;');
    }

    public function testAStaleAnswerShowsTheBannerAndAFreshOneHidesIt(): void
    {
        self::visit('/korbo26/');
        self::tap('.tabbar a[href="/korbo26/programy"]');
        self::waitFor('const s = document.querySelector(arguments[0]); return s && !s.hidden && s.querySelector(".tl-card") !== null;', [self::SCREEN]);
        self::assertNull(self::banner(), 'fresh: no banner');

        self::script(self::STALE_REVALIDATIONS);
        self::revalidate();
        self::waitFor('return document.querySelector(arguments[0]).getAttribute("data-stale") === "1" && !document.querySelector("[data-netstate]").hidden;', [self::SCREEN]);
        // fetched moments ago: "právě teď" under a minute, never "před 0 min"
        self::assertMatchesRegularExpression('/^Nepodařilo se načíst aktuální data\. Zobrazuji stav z \d\d:\d\d \((právě teď|před \d+ min)\)\.$/u', (string) self::banner());

        // the server answers fresh again: the attribute goes, and the banner with it
        self::script('window.__stale = false;');
        self::revalidate();
        self::waitFor('return !document.querySelector(arguments[0]).hasAttribute("data-stale") && document.querySelector("[data-netstate]").hidden;', [self::SCREEN]);
    }

    /** The banner is the visible screen's: a stale screen left for a fresh one takes its banner along. */
    public function testTheBannerFollowsTheVisibleScreen(): void
    {
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(\'[data-screen="/korbo26/informace"]\') !== null;');
        self::script(<<<'JS'
            const section = document.querySelector('[data-screen="/korbo26/programy"]');
            section.setAttribute('data-stale', '1');
            section.dispatchEvent(new CustomEvent('screen:freshness', {bubbles: true}));
            JS);
        self::waitFor('return !document.querySelector("[data-netstate]").hidden;');

        self::assertStringStartsWith('Nepodařilo se načíst aktuální data.', (string) self::banner());

        self::tap('.tabbar a[href="/korbo26/informace"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/informace"]\'); return s && !s.hidden;');
        self::assertNull(self::banner(), 'Informace is fresh');
    }
}
