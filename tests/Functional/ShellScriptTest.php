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
        $html = (string) $this->request($this->createApp(), 'GET', '/odkazy')->getBody();

        self::assertMatchesRegularExpression('~<script src="shell\.js\?v=[0-9a-f]{8}" defer></script>~', $html);
        self::assertStringContainsString("navigator.serviceWorker.register('sw.js', {scope: meta('event-base')})", self::script());
    }

    private static function stylesheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
    }

    public function testTheProfileOffersAHiddenInstallButton(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/profil')->getBody();

        self::assertStringContainsString('<p class="install" data-install hidden><button type="button" class="btn install-btn">Přidat na plochu</button></p>', $html);
    }

    public function testTheFreshnessWordingIsTheSpecs(): void
    {
        $js = self::script();

        self::assertStringContainsString("return 'offline · z ' + time;", $js);
        self::assertStringContainsString("'naposledy načteno ' + time : 'aktualizováno ' + time", $js);
        self::assertStringContainsString('if (navigator.onLine === false) {', $js);
        self::assertStringContainsString("section.getAttribute('data-stale') === '1'", $js);
    }

    /** Carry-over: a deferred morph updates the attributes late, so the line is never cached. */
    public function testTheLabelIsReadFromTheAttributesEveryTimeItIsDrawn(): void
    {
        $js = self::script();

        self::assertSame(1, substr_count($js, "section.getAttribute('data-fetched-at')"));
        self::assertStringContainsString('var text = freshnessText(section);', $js);
        self::assertStringContainsString("['screen:shown', 'screen:morphed', 'screen:freshness'].forEach(function (name) {", $js);
        self::assertStringContainsString("window.addEventListener('online', function () {", $js);
        self::assertStringContainsString("window.addEventListener('offline', function () {", $js);
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

    public function testTheOneScreenfulScreensGiveTheFreshnessLineItsHeightBack(): void
    {
        $css = self::stylesheet();

        self::assertStringContainsString('.screen:has(> .freshness:not([hidden])) {', $css);
        self::assertSame(1, preg_match('/^\.pg \{([^}]*)\}/m', $css, $pg));
        self::assertStringContainsString('min-height: calc(var(--screen-height) - var(--freshness-space));', $pg[1]);
        self::assertSame(1, preg_match('/^div\.map \{([^}]*)\}/m', $css, $map));
        self::assertStringContainsString('height: calc(var(--screen-height) - var(--freshness-space));', $map[1]);
    }
}
