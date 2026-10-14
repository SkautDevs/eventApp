<?php

declare(strict_types=1);

namespace Tests\Functional;

/** The two optional hooks an event may use for a look the shared stylesheet cannot carry. */
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
            '~<link rel="stylesheet" href="style\.css\?v=[0-9a-f]{8}">\s*<link rel="stylesheet" href="' . preg_quote(self::SHEET, '~') . '\?v=' . $hash . '">~',
            $html,
        );
    }

    public function testAnEventWithoutAStylesheetLinksOnlyTheSharedOne(): void
    {
        self::assertSame(1, preg_match_all('~<link rel="stylesheet" href="(?!/vendor/)~', $this->home('minimal')));
        self::assertSame(1, preg_match_all('~<link rel="stylesheet" href="(?!/vendor/)~', (string) $this->request($this->createApp('obrok19'), 'GET', '/')->getBody()));
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
