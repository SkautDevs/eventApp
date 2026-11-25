<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The install card's cases (www/js/shell/install.js): one paragraph per platform, the card
 * hidden where nothing applies. Headless Chrome offers the install at a moment of its own
 * choosing, so noInstallOffers() stops its trusted offer and only the synthetic offers below
 * reach the page. Each step runs in one synchronous script.
 */
#[Group('browser')]
final class InstallTest extends BrowserTestCase
{
    /**
     * Pretends a user agent (null: keep the page's) and an installed app, redraws the visible
     * screen and says whether its card shows, and which paragraphs.
     */
    private const string DRAWN = <<<'JS'
        const [ua, installed] = arguments;
        if (ua !== null) { Object.defineProperty(navigator, 'userAgent', {get: () => ua, configurable: true}); }
        if (installed) { Object.defineProperty(navigator, 'standalone', {get: () => true, configurable: true}); }
        const screen = document.querySelector('.screen:not([hidden])');
        screen.dispatchEvent(new CustomEvent('screen:shown', {bubbles: true}));
        const card = screen.querySelector('[data-install-card]');
        return {card: !card.hidden, shown: Array.from(card.querySelectorAll('[data-install]')).filter(p => !p.hidden).map(p => p.dataset.install)};
        JS;

    /** A synthetic offer whose prompt counts its showings; says whether the page kept it (default prevented). */
    private const string OFFER = <<<'JS'
        window.prompted = 0;
        const offer = new Event('beforeinstallprompt', {cancelable: true});
        offer.prompt = () => { window.prompted++; return Promise.resolve(); };
        window.dispatchEvent(offer);
        return offer.defaultPrevented;
        JS;

    private const string IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    private const string ANDROID_FIREFOX = 'Mozilla/5.0 (Android 14; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0';
    private const string DESKTOP = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        self::noInstallOffers();
    }

    /** @return array<string, array{string, string|null}> UA => the paragraph shown (null: the card is hidden) */
    public static function platforms(): array
    {
        return [
            'iPhone Safari' => [self::IPHONE_SAFARI, 'ios'],
            'iPhone Chrome' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1', 'ios'],
            'Android Firefox' => [self::ANDROID_FIREFOX, 'android'],
            'Instagram on Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/UQ1A.240205.004; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.6099.230 Mobile Safari/537.36 Instagram 312.0.0.0.0 Android', 'inApp'],
            'Facebook on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/450.0.0.38.108;FBBV/565075686]', 'inApp'],
            'a computer' => [self::DESKTOP, null],
        ];
    }

    #[DataProvider('platforms')]
    public function testEachPlatformGetsItsParagraph(string $ua, ?string $paragraph): void
    {
        $expected = $paragraph === null ? ['card' => false, 'shown' => []] : ['card' => true, 'shown' => [$paragraph]];
        foreach (['/korbo26/', '/korbo26/profil'] as $path) {
            self::visit($path);
            self::waitFor('return document.readyState === "complete";');

            self::assertSame($expected, self::script(self::DRAWN, [$ua, false]), $path);
        }
    }

    public function testAnOfferShowsTheButtonAndThePromptHidesTheCard(): void
    {
        self::visit('/korbo26/profil');
        self::waitFor('return document.readyState === "complete";');

        self::assertSame(['card' => false, 'shown' => []], self::script(self::DRAWN, [self::DESKTOP, false]));
        // the offer is kept, not shown at once
        self::assertTrue(self::script(self::OFFER));
        self::assertSame(['card' => true, 'shown' => ['offer']], self::script(self::DRAWN, [null, false]));
        self::assertSame(0, self::script('return window.prompted;'));

        // the tap shows the kept offer once, and the card goes
        $tapped = self::script(<<<'JS'
            document.querySelector('.screen:not([hidden]) .install-btn').click();
            document.querySelector('.screen:not([hidden]) .install-btn').click();
            return {card: !document.querySelector('.screen:not([hidden]) [data-install-card]').hidden, prompted: window.prompted};
            JS);
        self::assertSame(['card' => false, 'prompted' => 1], $tapped);

        $manifest = self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            fetch(document.querySelector('link[rel=manifest]').href).then(response => response.json()).then(done, error => done({error: String(error)}));
            JS);
        self::assertContains('maskable', array_column($manifest['icons'] ?? [], 'purpose'));
    }

    public function testALateOfferReplacesTheAndroidParagraph(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        self::assertSame(['card' => true, 'shown' => ['android']], self::script(self::DRAWN, [self::ANDROID_FIREFOX, false]));
        self::assertTrue(self::script(self::OFFER));
        self::assertSame(['card' => true, 'shown' => ['offer']], self::script(self::DRAWN, [null, false]));
    }

    public function testAppInstalledHidesTheCard(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        self::assertTrue(self::script(self::OFFER));
        self::assertSame(['card' => true, 'shown' => ['offer']], self::script(self::DRAWN, [null, false]));
        self::script("window.dispatchEvent(new Event('appinstalled'));");
        self::assertSame(['card' => false, 'shown' => []], self::script(self::DRAWN, [null, false]));
    }

    /** An installed app needs no install, whatever the platform and even with an offer kept. */
    public function testAnInstalledAppHidesTheCardOnBothScreens(): void
    {
        foreach (['/korbo26/', '/korbo26/profil'] as $path) {
            self::visit($path);
            self::waitFor('return document.readyState === "complete";');

            self::assertTrue(self::script(self::OFFER), $path);
            self::assertSame(['card' => false, 'shown' => []], self::script(self::DRAWN, [self::IPHONE_SAFARI, true]), $path);
        }
    }
}
