<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * Fragment mode: `X-Screen: 1` renders a screen without the shell, so the loader in
 * www/app.js can swap it into a running document. The same route without the header
 * still renders the whole page — that is what keeps deep links, crawlers, a no-JS
 * reader and the back button against a cold cache working.
 */
final class ScreenFragmentTest extends AppTestCase
{
    /** The five tab destinations plus /profil — the screens the loader participates in. */
    private const SCREENS = [
        '/' => 'homepage',
        '/programy' => 'programs',
        '/mapa' => 'map',
        '/novinky' => 'news',
        '/odkazy' => 'links',
        '/profil' => '',
    ];

    public function testFragmentCarriesNoShell(): void
    {
        $app = $this->createApp();

        foreach (array_keys(self::SCREENS) as $path) {
            $html = (string) $this->request($app, 'GET', $path, null, ['X-Screen' => '1'])->getBody();

            $this->assertStringNotContainsString('<html', $html, $path);
            $this->assertStringNotContainsString('<!DOCTYPE', $html, $path);
            $this->assertStringNotContainsString('class="appbar"', $html, $path);
            $this->assertStringNotContainsString('class="tabbar"', $html, $path);
            $this->assertStringStartsWith('<section class="screen"', trim($html), $path);
        }
    }

    public function testFragmentCarriesTheMetadataTheShellNeeds(): void
    {
        $app = $this->createApp();

        foreach (self::SCREENS as $path => $tab) {
            $html = (string) $this->request($app, 'GET', $path, null, ['X-Screen' => '1'])->getBody();

            $this->assertStringContainsString('data-screen="' . $app->getBasePath() . $path . '"', $html, $path);
            $this->assertStringContainsString('data-tab="' . $tab . '"', $html, $path);
            $this->assertMatchesRegularExpression('/data-title="[^"]+"/', $html, $path);
            $this->assertMatchesRegularExpression('/data-doc-title="[^"]+"/', $html, $path);
            $this->assertStringContainsString('tabindex="-1"', $html, $path);
        }
    }

    public function testAPlainRequestStillRendersTheWholePage(): void
    {
        $app = $this->createApp();

        foreach (array_keys(self::SCREENS) as $path) {
            $response = $this->request($app, 'GET', $path);
            $html = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertStringContainsString('<!DOCTYPE html>', $html, $path);
            $this->assertStringContainsString('class="appbar"', $html, $path);
            $this->assertStringContainsString('Hlavní menu', $html, $path);
            // the whole page wraps its content in exactly the same screen element
            $this->assertStringContainsString('data-screen="' . $app->getBasePath() . $path . '"', $html, $path);
        }
    }

    public function testTheTwoResponsesAreKeyedApartForCaches(): void
    {
        $app = $this->createApp();

        $this->assertSame('X-Screen', $this->request($app, 'GET', '/')->getHeaderLine('Vary'));
    }

    /**
     * The error middleware answers a 404 without ever calling the route, so the header
     * has to sit outside it. There is no shared cache in front of the app today, which
     * is what makes this harmless today rather than forever.
     */
    public function testAnErrorResponseIsKeyedApartForCachesToo(): void
    {
        $app = $this->createApp();

        $response = $this->request($app, 'GET', '/tohle-tady-neni');
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('X-Screen', $response->getHeaderLine('Vary'));

        $fragment = $this->request($app, 'GET', '/tohle-tady-neni', null, ['X-Screen' => '1']);
        self::assertSame(404, $fragment->getStatusCode());
        self::assertSame('X-Screen', $fragment->getHeaderLine('Vary'));
    }

    /**
     * A missing route answers with the whole error page in both modes — there is no
     * fragment-shaped 404 and there should not be one, because a page with an app bar
     * in it injected into a <section> would put a second app bar inside the app. The
     * loader treats any non-200 as "not a screen" and hands the URL to the browser.
     */
    public function testAMissingScreenIsLeftToARealNavigation(): void
    {
        $app = $this->createApp();

        $html = (string) $this->request($app, 'GET', '/tohle-tady-neni', null, ['X-Screen' => '1'])->getBody();
        // the whole document, shell and all — never a bare section the loader could insert
        self::assertStringStartsWith('<!DOCTYPE html>', trim($html));
        self::assertStringContainsString('class="appbar"', $html);

        $loader = (string) file_get_contents(dirname(__DIR__, 2) . '/www/app.js');
        self::assertStringContainsString('if (!response.ok) {', $loader);
        self::assertStringContainsString('location.href = path;', $loader);
    }

    public function testTheProgramScreenCarriesNoInlineScriptAnyMore(): void
    {
        $app = $this->createApp();

        // <head> is the one place a screen swap cannot reach, so the Program screen's
        // behaviour has to be a file the shell loads once
        $fragment = (string) $this->request($app, 'GET', '/programy', null, ['X-Screen' => '1'])->getBody();
        $this->assertStringContainsString('data-pg-root', $fragment);
        $this->assertStringNotContainsString('<script', $fragment);

        $page = (string) $this->request($app, 'GET', '/programy')->getBody();
        $this->assertStringContainsString('src="programs.js', $page);
        $this->assertStringContainsString('src="app.js', $page);
    }

    public function testBothModesWorkForTheOtherEventToo(): void
    {
        $app = $this->createApp('obrok27');

        foreach (self::SCREENS as $path => $tab) {
            $fragment = $this->request($app, 'GET', $path, null, ['X-Screen' => '1']);
            $this->assertSame(200, $fragment->getStatusCode(), $path);
            $this->assertStringContainsString('data-tab="' . $tab . '"', (string) $fragment->getBody(), $path);
        }
    }

    /**
     * A route outside the loader's set never has to answer in fragment mode, but it
     * must not blow up if something asks: it simply keeps its shell.
     */
    public function testANonParticipatingRouteIgnoresTheHeader(): void
    {
        $app = $this->createApp();
        $_ENV['ADMIN_TOKEN_OBROK19'] = 'test-token';
        $this->request($app, 'GET', '/admin/notify?token=test-token');

        $html = (string) $this->request($app, 'GET', '/admin/notify', null, ['X-Screen' => '1'])->getBody();
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }

    /** Round C prints "aktualizováno HH:MM" from this; it must be on every screen, in both modes. */
    public function testEveryScreenCarriesItsDataTimestampInBothModes(): void
    {
        $app = $this->createApp();

        foreach (array_keys(self::SCREENS) as $path) {
            foreach ([[], ['X-Screen' => '1']] as $headers) {
                $html = (string) $this->request($app, 'GET', $path, null, $headers)->getBody();
                $mode = $path . ($headers === [] ? ' (page)' : ' (fragment)');

                $this->assertMatchesRegularExpression(
                    '/<section class="screen"[^>]*\sdata-fetched-at="\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+0[12]:00"/',
                    $html,
                    $mode,
                );
                // the stub is never stale
                $this->assertStringNotContainsString('data-stale', $html, $mode);
            }
        }
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_TOKEN_OBROK19']);
        parent::tearDown();
    }

    /** shell.js fills it; the server only states where it goes. */
    public function testEveryScreenHasAnEmptyFreshnessLineRightAfterItsHeading(): void
    {
        $app = $this->createApp();

        foreach (array_keys(self::SCREENS) as $path) {
            foreach ([[], ['X-Screen' => '1']] as $headers) {
                $html = (string) $this->request($app, 'GET', $path, null, $headers)->getBody();
                $mode = $path . ($headers === [] ? ' (page)' : ' (fragment)');

                self::assertSame(1, substr_count($html, 'data-freshness'), $mode);
                self::assertMatchesRegularExpression('~<section class="screen"[^>]*\sdata-fetched-at="[^"]+"[^>]*><h1 class="sr-only">[^<]*</h1><p class="freshness" data-freshness hidden></p>~', $html, $mode);
            }
        }
    }

    public function testTheNoscriptNoticeIsInTheDocumentOnly(): void
    {
        $app = $this->createApp();

        self::assertStringContainsString(
            '<main id="obsah" tabindex="-1">' . "\n\t" . '<noscript><p class="noscript">Aplikace potřebuje zapnutý JavaScript.</p></noscript>',
            (string) $this->request($app, 'GET', '/')->getBody(),
        );
        self::assertStringNotContainsString('<noscript>', (string) $this->request($app, 'GET', '/', null, ['X-Screen' => '1'])->getBody());
    }

    public function testTheTabBarCarriesTheLoadingBar(): void
    {
        $app = $this->createApp();

        self::assertMatchesRegularExpression(
            '~<nav class="tabbar" aria-label="Hlavní menu">\s*<span class="progress" data-progress aria-hidden="true"></span>~',
            (string) $this->request($app, 'GET', '/novinky')->getBody(),
        );
        self::assertStringNotContainsString('data-progress', (string) $this->request($app, 'GET', '/novinky', null, ['X-Screen' => '1'])->getBody());
    }
}
