<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\SkautisGatewayInterface;

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

    /** /profil asks the gateway for its login URL, so every app here gets the fake one. */
    private function app(string $slug = 'obrok19'): \Slim\App
    {
        return $this->createApp($slug, [SkautisGatewayInterface::class => new FakeSkautisGateway()]);
    }

    public function testFragmentCarriesNoShell(): void
    {
        $app = $this->app();

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
        $app = $this->app();

        foreach (self::SCREENS as $path => $tab) {
            $html = (string) $this->request($app, 'GET', $path, null, ['X-Screen' => '1'])->getBody();

            $this->assertStringContainsString('data-screen="' . $path . '"', $html, $path);
            $this->assertStringContainsString('data-tab="' . $tab . '"', $html, $path);
            $this->assertMatchesRegularExpression('/data-title="[^"]+"/', $html, $path);
            $this->assertMatchesRegularExpression('/data-doc-title="[^"]+"/', $html, $path);
            $this->assertStringContainsString('tabindex="-1"', $html, $path);
        }
    }

    public function testAPlainRequestStillRendersTheWholePage(): void
    {
        $app = $this->app();

        foreach (array_keys(self::SCREENS) as $path) {
            $response = $this->request($app, 'GET', $path);
            $html = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertStringContainsString('<!DOCTYPE html>', $html, $path);
            $this->assertStringContainsString('class="appbar"', $html, $path);
            $this->assertStringContainsString('Hlavní menu', $html, $path);
            // the whole page wraps its content in exactly the same screen element
            $this->assertStringContainsString('data-screen="' . $path . '"', $html, $path);
        }
    }

    public function testTheTwoResponsesAreKeyedApartForCaches(): void
    {
        $app = $this->app();

        $this->assertSame('X-Screen', $this->request($app, 'GET', '/')->getHeaderLine('Vary'));
    }

    public function testTheProgramScreenCarriesNoInlineScriptAnyMore(): void
    {
        $app = $this->app();

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
        $app = $this->app('obrok27');

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
        $app = $this->app();
        $_ENV['ADMIN_TOKEN'] = 'test-token';

        $html = (string) $this->request($app, 'GET', '/admin/notify?token=test-token', null, ['X-Screen' => '1'])->getBody();
        $this->assertStringContainsString('<!DOCTYPE html>', $html);

        unset($_ENV['ADMIN_TOKEN']);
    }
}
