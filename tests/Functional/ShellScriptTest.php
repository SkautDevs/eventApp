<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * www/shell.js: the installed-app concerns. What a test can hold here is its contract;
 * the behaviour is proved in a browser (tests/Browser).
 */
final class ShellScriptTest extends AppTestCase
{
    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/www/shell.js');
    }

    public function testTheShellRegistersTheWorkerOnEveryPage(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/informace')->getBody();

        self::assertMatchesRegularExpression('~<script src="shell\.js\?v=[0-9a-f]{8}" defer></script>~', $html);
        self::assertStringContainsString("navigator.serviceWorker.register('sw.js', {scope: meta('event-base')})", self::script());
    }

    private static function stylesheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
    }

    public function testAMapThatCannotLoadOfflineSaysSo(): void
    {
        $shell = self::script();
        self::assertStringContainsString("document.querySelectorAll('[data-map-offline]')", $shell);
        self::assertStringContainsString("window.addEventListener('online', drawMap);", $shell);
        // one capture-phase listener marks a map loaded, whichever screen it arrives on
        self::assertStringContainsString("document.addEventListener('load', function (event) {", $shell);
        self::assertStringContainsString("iframe.matches('[data-map] iframe')", $shell);
        self::assertStringContainsString('}, true);', $shell);
        self::assertStringNotContainsString('mapWatched', $shell);
        // a loaded map offline needs no note
        self::assertStringContainsString("note.hidden = !(map && map.hasAttribute('data-map') && map.hidden);", $shell);
        // the note may carry the plan's button (www/map.js handles it), so shell.js only
        // ever shows or hides it and never rewrites what it says
        self::assertStringNotContainsString('note.textContent', $shell);
        self::assertStringNotContainsString('note.innerHTML', $shell);
    }

    public function testTheHomepageAndTheProfileOfferAHiddenInstall(): void
    {
        foreach (['/' => 'home', '/profil' => 'profile'] as $path => $flag) {
            $html = (string) $this->request($this->createApp(), 'GET', $path)->getBody();

            self::assertStringContainsString('<section class="install-card" aria-label="Instalace" data-install-card="' . $flag . '">', $html, $path);
            self::assertStringContainsString('<p class="install" data-install hidden><button type="button" class="btn install-btn">Uložit jako aplikaci</button></p>', $html, $path);
            self::assertStringContainsString('<p class="install" data-install-ios hidden><span class="notice">Aplikaci si přidáš na plochu v Safari: klepni na Sdílet a pak na Přidat na plochu.</span></p>', $html, $path);
            self::assertMatchesRegularExpression('#<button type="button" class="btn btn-primary" data-push-toggle>\s*Povolit notifikace\s*</button>#', $html, $path);
        }
    }

    /** On Domů the whole card goes in an installed app; on Profil only its install half. */
    public function testAnInstalledAppHidesTheHomeCardAndOnlyTheInstallHalfOnProfil(): void
    {
        $js = self::script();

        self::assertStringContainsString("document.querySelectorAll('[data-install-card=\"home\"]')", $js);
        self::assertStringContainsString('card.hidden = standalone();', $js);
        self::assertStringContainsString('el.hidden = deferredPrompt === null || standalone();', $js);
    }

    /** The home screen's next programmes and countdown, drawn from the device clock. */
    public function testTheHomeScreenIsDrawnFromTheDeviceClock(): void
    {
        $js = self::script();

        // the server writes data-until as "do HH:MM", so the running lead reads "Právě · do HH:MM"
        foreach (["'Právě · ' + card.dataset.until", "'Za '", 'data-countdown', 'data-next-lead', "['den', 'dny', 'dní']"] as $needle) {
            self::assertStringContainsString($needle, $js);
        }
        // up to three cards: the running one and the next two
        self::assertStringContainsString('NEXT_SHOWN = 3', $js);
        // the countdown counts Prague days, whatever zone the phone is in
        self::assertStringContainsString("timeZone: 'Europe/Prague'", $js);
        self::assertStringContainsString("document.addEventListener('DOMContentLoaded', drawHome);", $js);
        self::assertStringContainsString("document.addEventListener('pg:tick', drawHome);", $js);
    }

    public function testTheIosHintIsDrawnOnlyOutsideAnInstalledApp(): void
    {
        $js = self::script();

        self::assertStringContainsString("el.hidden = !ios() || standalone();", $js);
        self::assertStringContainsString('return navigator.standalone === true', $js);
    }

    /** The network banner's two sentences, and the offline toast's (spec: Network banner, Toasts). */
    public function testTheBannerAndToastWordingIsTheSpecs(): void
    {
        $js = self::script();

        foreach ([
            'Jsi offline. Data byla naposledy aktualizována v ',
            'Nepodařilo se načíst aktuální data. Zobrazuji stav z ',
            'Akci není možné provést, protože jsi offline.',
            'Zkus to znovu až najdeš místo se signálem.',
            'data-netstate',
            'data-toast',
            // never "(před 0 min)" (spec: Network banner)
            "'(právě teď)'",
            "'(před ' + minutes + ' min)'",
            "'(před ' + Math.floor(minutes / 60) + ' h)'",
            "'z ' + at.getDate() + '. ' + (at.getMonth() + 1) + '.'",
        ] as $needle) {
            self::assertStringContainsString($needle, $js);
        }
        self::assertStringContainsString('if (navigator.onLine === false) {', $js);
        self::assertStringContainsString("section.getAttribute('data-stale') === '1'", $js);
        // the per-screen line is gone for the banner
        self::assertStringNotContainsString('data-freshness', $js);
        self::assertStringNotContainsString('aktualizováno ', $js);
    }

    /** Carry-over: a deferred morph updates the attributes late, so the banner never remembers them. */
    public function testTheBannerIsReadFromTheVisibleScreenEveryTimeItIsDrawn(): void
    {
        $js = self::script();

        self::assertSame(1, substr_count($js, "section.getAttribute('data-fetched-at')"));
        self::assertStringContainsString("['screen:shown', 'screen:morphed', 'screen:freshness'].forEach(function (name) {", $js);
        self::assertStringContainsString("window.addEventListener('online', drawNetstate);", $js);
        self::assertStringContainsString("window.addEventListener('offline', drawNetstate);", $js);
        self::assertStringContainsString('setInterval(drawNetstate, 60000);', $js);
    }

    /**
     * The banner is bottom chrome: its height is published on the root, and the docked view
     * switcher, every screen's foot and the one-screenful heights make room for it. Its
     * ticking age is not what a screen reader hears: a live region of its own says the rest.
     */
    public function testTheBannerMakesRoomForItselfAndSpeaksWithoutItsAge(): void
    {
        $js = self::script();
        $css = self::stylesheet();

        self::assertStringContainsString("root.style.setProperty('--netstate-height', height + 'px');", $js);
        self::assertStringContainsString("document.querySelector('[data-netstate-live]')", $js);
        // a box that changes by itself (a font, a rotation) is measured again
        self::assertStringContainsString('}).observe(banner);', $js);

        self::assertStringContainsString('--netstate-height: 0px;', $css);
        self::assertStringContainsString('- env(safe-area-inset-bottom, 0px) - var(--netstate-height));', $css, '--screen-height leaves it out');
        self::assertSame(1, preg_match('/^\.tabs-wrap \{([^}]*)\}/m', $css, $wrap));
        self::assertStringContainsString('bottom: calc(var(--tabbar-height) + env(safe-area-inset-bottom, 0px) + var(--netstate-height));', $wrap[1]);
        self::assertSame(1, preg_match('/^\.screen \{([^}]*)\}/m', $css, $screen));
        self::assertStringContainsString('var(--netstate-height)', $screen[1]);
        self::assertSame(1, preg_match('/^\.screen\[data-tab="programs"\] \{([^}]*)\}/m', $css, $programs));
        self::assertStringContainsString('var(--netstate-height)', $programs[1]);
    }

    /**
     * The server's relative times and Domů's date are redrawn from the device clock, with
     * NewsItems::relative()'s bands, on the home screen's triggers.
     */
    public function testRelativeTimesAndTheHomeDateAreRedrawnFromTheDeviceClock(): void
    {
        $js = self::script();

        foreach (["document.querySelectorAll('[data-sent-at]')", "document.querySelectorAll('[data-home-date]')", "return 'právě teď';", "'před ' + Math.floor(seconds / 60) + ' min'", "'před ' + Math.floor(seconds / 3600) + ' h'", "'včera ' + time", "hourCycle: 'h23'"] as $needle) {
            self::assertStringContainsString($needle, $js);
        }
        self::assertStringContainsString("drawHomeDate(now);
		drawSentAt(now);", $js);
    }

    /** A toast is the shell's: one at a time, gone after 6 s or on its close button. */
    public function testTheToastIsShownOnceAndLeavesByItself(): void
    {
        $js = self::script();

        self::assertStringContainsString('function showToast(title, line) {', $js);
        self::assertStringContainsString('var TOAST_MS = 6000;', $js);
        self::assertStringContainsString("closest('[data-toast-close]')", $js);
        // a toast leaving with the focus hands it to the visible screen, not to <body> — or,
        // with the programme sheet open, to the sheet's card, the one thing not inert
        self::assertStringContainsString('var focused = toast.contains(document.activeElement);', $js);
        self::assertStringContainsString("var dialog = section.querySelector('.sheet.is-open [role=\"dialog\"]');", $js);
        self::assertStringContainsString('(dialog || section).focus({preventScroll: true});', $js);
        // #offline is the worker's redirect after a write that never reached the server
        self::assertStringContainsString("location.hash === '#offline'", $js);
        self::assertStringContainsString('history.replaceState(history.state, \'\', location.pathname + location.search);', $js);
        // built from text, never from markup
        self::assertStringNotContainsString('innerHTML', $js);
    }

    /** The two-tap confirmation is the shell's, so Profil's logout and the sheet's unregister share it. */
    public function testTheTwoTapConfirmationIsTheShells(): void
    {
        $js = self::script();

        self::assertStringContainsString("event.target.closest('[data-confirm]')", $js);
        self::assertStringContainsString("button.textContent = button.getAttribute('data-confirm');", $js);
        self::assertStringNotContainsString('data-confirm', (string) file_get_contents(dirname(__DIR__, 2) . '/www/profile.js'));
        // a closed sheet withdraws the question its unregister button was asking
        self::assertStringContainsString("document.addEventListener('sheet:closed'", $js);
        self::assertStringContainsString("new CustomEvent('sheet:closed', {bubbles: true})", (string) file_get_contents(dirname(__DIR__, 2) . '/www/programs.js'));
    }

    public function testTheInstallPromptWaitsForTheReader(): void
    {
        $js = self::script();

        self::assertStringContainsString("window.addEventListener('beforeinstallprompt', function (event) {", $js);
        self::assertStringContainsString('event.preventDefault();', $js);
        self::assertStringContainsString('offer.prompt();', $js);
        self::assertStringContainsString("window.addEventListener('appinstalled', function () {", $js);
        self::assertStringContainsString("'(display-mode: standalone)'", $js);
    }

    public function testTheLoadingBarFollowsTheLoaderEvents(): void
    {
        $js = self::script();

        self::assertStringContainsString("document.addEventListener('screen:loading', function () {", $js);
        self::assertStringContainsString("root.setAttribute('data-loading', '');", $js);
        self::assertStringContainsString("document.addEventListener('screen:loaded', function () {", $js);
        self::assertStringContainsString("root.removeAttribute('data-loading');", $js);
    }

    public function testTheLoadingBarIsDrawnInTheTabBarsInk(): void
    {
        self::assertSame(1, preg_match('/^\.progress \{([^}]*)\}/m', self::stylesheet(), $block));
        self::assertStringContainsString('background: var(--on-structure);', $block[1]);
        self::assertStringContainsString(':root[data-loading] .progress {', self::stylesheet());
    }

    /** The freshness line and its height plumbing are gone with it. */
    public function testTheStylesheetCarriesNoFreshnessLine(): void
    {
        $css = self::stylesheet();

        self::assertStringNotContainsString('.freshness', $css);
        self::assertStringNotContainsString('--freshness-', $css);
        self::assertSame(1, preg_match('/^\.pg \{([^}]*)\}/m', $css, $pg));
        self::assertStringContainsString('min-height: var(--screen-height);', $pg[1]);
    }

    /** The banner docks above the tab bar; the toast sits under the app bar. Both are bars: structure under its ink, with a hairline. */
    public function testTheBannerAndTheToastAreStructureWithAHairline(): void
    {
        $css = self::stylesheet();

        foreach (['.netstate', '.toast'] as $selector) {
            self::assertSame(1, preg_match('/^' . preg_quote($selector, '/') . ' \{([^}]*)\}/m', $css, $rule), $selector);
            self::assertStringContainsString('position: fixed;', $rule[1], $selector);
            self::assertStringContainsString('background-color: var(--structure);', $rule[1], $selector);
            self::assertStringContainsString('color: var(--on-structure);', $rule[1], $selector);
            self::assertStringContainsString('var(--edge-width) solid var(--edge)', $rule[1], $selector);
        }
        self::assertSame(1, preg_match('/^\.netstate \{([^}]*)\}/m', $css, $banner));
        self::assertStringContainsString('bottom: calc(var(--tabbar-height) + env(safe-area-inset-bottom, 0px));', $banner[1]);
        self::assertSame(1, preg_match('/^\.toast \{([^}]*)\}/m', $css, $toast));
        self::assertStringContainsString('top: calc(var(--appbar-height) + var(--appbar-inset) + 8px);', $toast[1]);
        self::assertSame(1, preg_match('/^\.toast-close \{([^}]*)\}/m', $css, $close));
        self::assertStringContainsString('width: 2.75rem;', $close[1]);
        self::assertStringContainsString('height: 2.75rem;', $close[1]);
    }
}
