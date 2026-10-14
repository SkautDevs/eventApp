<?php

declare(strict_types=1);

namespace Tests\Functional;

final class Obrok27Test extends AppTestCase
{
    public function testObrok27Boots(): void
    {
        $response = $this->request($this->createApp('obrok27'), 'GET', '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Obrok 2027', (string) $response->getBody());
    }

    public function testEveryEnabledFeatureRenders(): void
    {
        $app = $this->createApp('obrok27');
        foreach (['/novinky', '/mapa', '/odkazy', '/programy', '/profil'] as $uri) {
            self::assertSame(200, $this->request($app, 'GET', $uri)->getStatusCode(), $uri);
        }
    }

    public function testVisualIdentityIsTheBrandPalette(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString('--color-background: #f1f1f4', $html);
        self::assertStringContainsString('--color-base: #15133f', $html);
        self::assertStringContainsString('--color-primary: #ff5733', $html);
        self::assertStringContainsString('--role-bar: #15133f', $html);
        self::assertStringContainsString('--role-tabbar: #4d2f90', $html);
        self::assertStringNotContainsString('#c2ea3a', $html);
    }

    public function testActiveTabIsGreenWithDeepBlueInk(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/novinky')->getBody();

        self::assertStringContainsString('--role-state: #e0ff00', $html);
        self::assertStringContainsString('--role-on-state: #15133f', $html);
        self::assertStringContainsString('class="tab is-active" href="/obrok27/novinky"', $html);
    }

    public function testHomeTabUsesTheShortEventName(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString('<span>Obrok 27</span>', $html);
    }

    public function testTheAppBarCarriesTheLilieAndTheHomepageTheLogoWithoutIt(): void
    {
        $root = dirname(__DIR__, 2);
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString('<img class="appbar-logo" src="events/obrok27/O27_lilie_w.svg" alt="">', $html);
        self::assertStringContainsString('class="logo mainLogo mode-light-only" src="events/obrok27/O27_logo_d.svg"', $html);
        self::assertStringContainsString('class="logo mainLogo mode-dark-only" src="events/obrok27/O27_logo_w.svg"', $html);
        self::assertSame(1, preg_match('~<nav class="tabbar".*?</nav>~s', $html, $tabbar));
        self::assertStringContainsString('fas fa-home', $tabbar[0]);
        self::assertStringNotContainsString('tab-logo', $tabbar[0]);
        self::assertStringNotContainsString('events/obrok27/ghost', $html);
        self::assertSame([], glob($root . '/www/events/obrok27/ghost*') ?: []);
        foreach (['O27_lilie_w.svg', 'O27_logo_d.svg', 'O27_logo_w.svg'] as $file) {
            self::assertFileExists($root . '/www/events/obrok27/' . $file);
        }
    }

    public function testHandbookDownloadMissingFileIs404(): void
    {
        // the 2027 handbook PDF is not uploaded yet → page works, download 404s
        self::assertSame(404, $this->request($this->createApp('obrok27'), 'GET', '/handbook/download')->getStatusCode());
    }

    public function testTheIconsAreTheLilieOnDeepBlue(): void
    {
        $root = dirname(__DIR__, 2);
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        preg_match('#<link rel="icon" type="image/png" sizes="32x32" href="([^"]+)">#', $html, $m32);
        preg_match('#<link rel="apple-touch-icon" sizes="180x180" href="([^"]+)">#', $html, $mTouch);
        self::assertMatchesRegularExpression('#^/?events/obrok27/icon-[0-9a-f]{8}-32\.png$#', $m32[1] ?? '');
        self::assertMatchesRegularExpression('#^/?events/obrok27/icon-[0-9a-f]{8}-180\.png$#', $mTouch[1] ?? '');
        self::assertSame([32, 32], array_slice(getimagesize($root . '/www/' . ltrim($m32[1], '/')), 0, 2));
        self::assertSame([180, 180], array_slice(getimagesize($root . '/www/' . ltrim($mTouch[1], '/')), 0, 2));
        // the 2019 shields are gone, and so is the pinned-tab mask drawn from them
        self::assertFileDoesNotExist($root . '/www/events/obrok27/favicon-32x32.png');
        self::assertStringNotContainsString('rel="mask-icon"', $html);
    }

    public function testTheManifestIconsAreTheNewSet(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root . '/www/events/obrok27/site.webmanifest'), true);
        $sizes = [];
        foreach ($manifest['icons'] as $icon) {
            self::assertMatchesRegularExpression('#^/events/obrok27/icon-[0-9a-f]{8}-(192|512|maskable-512)\.png$#', $icon['src']);
            self::assertFileExists($root . '/www' . $icon['src']);
            $sizes[] = $icon['sizes'] . ($icon['purpose'] ?? '');
        }
        self::assertSame(['192x192', '512x512', '512x512maskable'], $sizes);
    }

    public function testTheManifestColoursAreTheBrandsDeepBlue(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root . '/www/events/obrok27/site.webmanifest'), true);
        self::assertSame('#15133F', $manifest['theme_color']);
        self::assertSame('#15133F', $manifest['background_color']);
    }

    public function testTheEventRunsFrom19To23May2027(): void
    {
        $config = require dirname(__DIR__, 2) . '/events/obrok27/config.php';

        self::assertSame(['start' => '2027-05-19', 'end' => '2027-05-23'], $config['dates']);
    }
}
