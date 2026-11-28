<?php

declare(strict_types=1);

namespace Tests\Functional;

/** The two optional hooks an event may use for a look the shared stylesheets cannot carry. */
final class EventStylesheetTest extends AppTestCase
{
    /** AssetVersion is final and hashes under the real www/, so the fixture names an existing file nothing else lists; it is only hashed, never parsed */
    private const string SHEET = 'sw.js';

    private function home(string $slug): string
    {
        $response = $this->request($this->createApp($slug, fixtureEvent: true), 'GET', '/');
        self::assertSame(200, $response->getStatusCode(), $slug);

        return (string) $response->getBody();
    }

    public function testTheEventStylesheetIsLinkedVersionedRightAfterTheSharedOne(): void
    {
        $html = $this->home('branded');

        $hash = substr((string) hash_file('sha256', dirname(__DIR__, 2) . '/www/' . self::SHEET), 0, 8);
        self::assertMatchesRegularExpression(
            '~<link rel="stylesheet" href="css/motion\.css\?v=[0-9a-f]{8}">\s*<link rel="stylesheet" href="' . preg_quote(self::SHEET, '~') . '\?v=' . $hash . '">~',
            $html,
        );
    }

    public function testTheCoreSheetsAreLinkedInTheirOrderAndTheEventsOwnComesLast(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();
        preg_match_all('~<link rel="stylesheet" href="(?!/vendor/)([^"?]+)\?v=[0-9a-f]{8}">~', $html, $links);
        self::assertSame(
            ['css/base.css', 'css/shell.css', 'css/info.css', 'css/inbox.css', 'css/home.css', 'css/profile.css', 'css/map.css', 'css/programs.css', 'css/admin.css', 'css/motion.css', 'events/obrok27/style.css'],
            $links[1],
        );
    }

    public function testAnEventWithoutAStylesheetLinksOnlyTheCoreSheets(): void
    {
        foreach (['minimal' => true, 'obrok19' => false] as $slug => $fixture) {
            $app = $this->createApp($slug, fixtureEvent: $fixture);
            $modules = $app->getContainer()?->get(\App\Module\Modules::class);
            self::assertInstanceOf(\App\Module\Modules::class, $modules);
            $html = (string) $this->request($app, 'GET', '/')->getBody();

            // every stylesheet but the vendor one, whatever its href: an empty one would show as ''
            preg_match_all('~<link rel="stylesheet" href="(?!/vendor/)([^"]*)">~', $html, $links);
            $paths = array_map(static fn(string $href): string => (string) preg_replace('~\?v=[0-9a-f]{8}$~', '', $href), $links[1]);
            self::assertSame($modules->sheets(), $paths, $slug);
            self::assertSame('css/motion.css', $paths[array_key_last($paths)], $slug);
        }
    }

    public function testAnEventWithoutMistaLinksNoMapSheet(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/')->getBody();
        self::assertStringNotContainsString('css/map.css', $html);
        self::assertStringContainsString('css/programs.css', $html);
    }

    public function testThePrecacheListCarriesTheVersionedStylesheetAndNotTheBarePath(): void
    {
        $response = $this->request($this->createApp('branded', fixtureEvent: true), 'GET', '/precache.json');
        $assets = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['assets'];

        $hash = substr((string) hash_file('sha256', dirname(__DIR__, 2) . '/www/' . self::SHEET), 0, 8);
        self::assertContains('/' . self::SHEET . '?v=' . $hash, $assets);
        self::assertNotContains('/' . self::SHEET, $assets);
    }

    public function testTheAppBarLogoWinsInTheAppBarAndStaysOutOfTheTabBar(): void
    {
        $html = $this->home('branded');
        $bar = (string) strstr((string) strstr($html, '<header class="appbar">'), '</header>', true);
        $tabs = (string) strstr($html, '<nav class="tabbar"');

        self::assertStringContainsString('src="events/branded/app-bar.svg"', $bar);
        self::assertStringNotContainsString('menu-on-state.svg', $bar);
        self::assertStringNotContainsString('menu.svg', $bar);
        self::assertStringNotContainsString('app-bar.svg', $tabs);
        self::assertStringContainsString('events/branded/menu.svg', $tabs);
    }

    public function testTheAppBarFallsBackToTheStateLogoThenTheMenuLogo(): void
    {
        self::assertStringContainsString('src="events/statelogo/menu-on-state.svg"', $this->appBar('statelogo'));
        self::assertStringNotContainsString('events/statelogo/menu.svg', $this->appBar('statelogo'));
        self::assertStringContainsString('src="events/menuonly/menu.svg"', $this->appBar('menuonly'));
        self::assertStringNotContainsString('<img', $this->appBar('minimal'));
    }

    private function appBar(string $slug): string
    {
        $html = $this->home($slug);

        return (string) strstr((string) strstr($html, '<header class="appbar">'), '</header>', true);
    }
}
