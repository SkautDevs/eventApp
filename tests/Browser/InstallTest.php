<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class InstallTest extends BrowserTestCase
{
    /**
     * Chrome under --headless=new does offer the install for this app, at a moment of its
     * own choosing, so the test cannot rely on the offer being absent. Each step below runs
     * in one synchronous script, so a real offer cannot land between a dispatch and its check.
     */
    public function testTheButtonFollowsTheOfferAndTheManifestHasAMaskableIcon(): void
    {
        self::visit('/korbo26/profil');
        self::waitFor('return document.readyState === "complete";');

        // an installed app drops any offer it was holding
        self::assertTrue(self::script(<<<'JS'
            window.dispatchEvent(new Event('appinstalled'));
            return document.querySelector('[data-install]').hidden;
            JS));

        // an offer shows the button and is kept, not shown at once
        $offered = self::script(<<<'JS'
            window.prompted = 0;
            const offer = new Event('beforeinstallprompt', {cancelable: true});
            offer.prompt = () => { window.prompted++; return Promise.resolve(); };
            window.dispatchEvent(offer);
            return {cancelled: offer.defaultPrevented, hidden: document.querySelector('[data-install]').hidden, prompted: window.prompted};
            JS);
        self::assertSame(['cancelled' => true, 'hidden' => false, 'prompted' => 0], $offered);

        // the tap shows the kept offer once, and the button goes
        $tapped = self::script(<<<'JS'
            document.querySelector('.install-btn').click();
            document.querySelector('.install-btn').click();
            return {hidden: document.querySelector('[data-install]').hidden, prompted: window.prompted};
            JS);
        self::assertSame(['hidden' => true, 'prompted' => 1], $tapped);

        $manifest = self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            fetch(document.querySelector('link[rel=manifest]').href).then(response => response.json()).then(done, error => done({error: String(error)}));
            JS);
        self::assertContains('maskable', array_column($manifest['icons'] ?? [], 'purpose'));
    }

    public function testTheHomepageCarriesTheOfferToo(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        $offered = self::script(<<<'JS'
            window.dispatchEvent(new Event('appinstalled'));
            const before = document.querySelector('.screen:not([hidden]) [data-install]').hidden;
            window.prompted = 0;
            const offer = new Event('beforeinstallprompt', {cancelable: true});
            offer.prompt = () => { window.prompted++; return Promise.resolve(); };
            window.dispatchEvent(offer);
            const shown = !document.querySelector('.screen:not([hidden]) [data-install]').hidden;
            document.querySelector('.screen:not([hidden]) .install-btn').click();
            return {before, shown, prompted: window.prompted, ios: document.querySelector('[data-install-ios]').hidden};
            JS);
        self::assertEquals(['before' => true, 'shown' => true, 'prompted' => 1, 'ios' => true], $offered);
    }

    /**
     * In an installed app Domů drops the whole card; Profil keeps it for the notifications
     * button and drops only the install half.
     */
    public function testAnInstalledAppHidesTheHomeCardAndOnProfilOnlyTheInstallHalf(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        $home = self::script(<<<'JS'
            const screen = document.querySelector('.screen:not([hidden])');
            const card = screen.querySelector('[data-install-card="home"]');
            const before = card.hidden;
            Object.defineProperty(navigator, 'standalone', {get: () => true, configurable: true});
            screen.dispatchEvent(new CustomEvent('screen:shown', {bubbles: true}));
            return {before, installed: card.hidden};
            JS);
        self::assertSame(['before' => false, 'installed' => true], $home);

        self::visit('/korbo26/profil');
        self::waitFor('return document.readyState === "complete";');

        $profile = self::script(<<<'JS'
            const screen = document.querySelector('.screen:not([hidden])');
            const card = screen.querySelector('[data-install-card="profile"]');
            const offer = new Event('beforeinstallprompt', {cancelable: true});
            offer.prompt = () => Promise.resolve();
            window.dispatchEvent(offer);
            const offered = !card.querySelector('[data-install]').hidden;
            Object.defineProperty(navigator, 'standalone', {get: () => true, configurable: true});
            screen.dispatchEvent(new CustomEvent('screen:shown', {bubbles: true}));
            const push = card.querySelector('[data-push-toggle]');
            return {
                offered,
                card: !card.hidden,
                install: !card.querySelector('[data-install]').hidden,
                ios: !card.querySelector('[data-install-ios]').hidden,
                push: push.textContent.trim() + (push.getClientRects().length > 0 ? ' visible' : ' hidden'),
            };
            JS);
        self::assertEquals(['offered' => true, 'card' => true, 'install' => false, 'ios' => false, 'push' => 'Povolit notifikace visible'], $profile);
    }

    /** iOS has no install prompt: an iPhone gets the hint, an installed app neither. */
    public function testOnIosTheHintShowsUntilInstalled(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        $drawn = self::script(<<<'JS'
            Object.defineProperty(navigator, 'userAgent', {get: () => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', configurable: true});
            const screen = document.querySelector('.screen:not([hidden])');
            screen.dispatchEvent(new CustomEvent('screen:shown', {bubbles: true}));
            const browser = !screen.querySelector('[data-install-ios]').hidden;
            Object.defineProperty(navigator, 'standalone', {get: () => true, configurable: true});
            screen.dispatchEvent(new CustomEvent('screen:shown', {bubbles: true}));
            return {browser, installed: !screen.querySelector('[data-install-ios]').hidden};
            JS);
        self::assertSame(['browser' => true, 'installed' => false], $drawn);
    }
}
