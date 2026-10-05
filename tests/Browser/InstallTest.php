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
}
