<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The toast in the running app: where the focus goes when one leaves with it, and that a
 * page carrying one is never what the worker keeps for offline.
 */
#[Group('browser')]
final class ToastShellTest extends BrowserTestCase
{
    /** The shell's offline toast, from the #offline the worker sends a failed write back with. */
    private static function offlineToast(string $path): void
    {
        self::visit($path . '#offline');
        self::waitFor('return document.querySelector("[data-toast]") !== null;');
        self::script('document.querySelector("[data-toast-close]").focus();');
        self::assertTrue(self::script('return document.activeElement !== null && document.activeElement.matches("[data-toast-close]");'));
    }

    public function testClosingAFocusedToastHandsTheFocusToTheScreen(): void
    {
        self::offlineToast('/korbo26/profil');

        self::tap('[data-toast-close]');

        self::assertNull(self::script('return document.querySelector("[data-toast]");'));
        self::assertSame('/korbo26/profil', self::script('return document.activeElement.getAttribute("data-screen");'), 'the focus is on the visible screen, not on <body>');
    }

    public function testAToastThatTimesOutWithTheFocusHandsItToTheScreenToo(): void
    {
        self::offlineToast('/korbo26/');

        self::waitFor('return document.querySelector("[data-toast]") === null;', [], 10);

        self::assertSame('/korbo26/', self::script('return document.activeElement.getAttribute("data-screen");'));
    }

    /**
     * M2: a page carrying a toast is shown and not stored. The reader is paired and the
     * worker holds the paired Profil; a toast then waits in the session (as a login or a
     * logout leaves one) and the next Profil says it. The offline copy must not, or it would
     * say it again every time Profil is opened without a signal.
     */
    public function testAPageWithAToastIsNeverTheOfflineCopy(): void
    {
        self::pair();
        $cache = self::waitForPrecache('korbo26');
        $stored = <<<'JS'
            const done = arguments[arguments.length - 1];
            const key = location.origin + '/korbo26/profil';
            caches.open(arguments[0])
                .then(cache => Promise.all([cache.match(key, {ignoreVary: true}), cache.match(key + '?x-screen=1', {ignoreVary: true})]))
                .then(([page, fragment]) => (page && fragment ? page.text() : null))
                .then(done, () => done(null));
            JS;
        // the refill after the login has put back the paired Profil, page and fragment
        $paired = <<<'JS'
            const done = arguments[arguments.length - 1];
            const key = location.origin + '/korbo26/profil';
            caches.open(arguments[0])
                .then(cache => Promise.all([cache.match(key, {ignoreVary: true}), cache.match(key + '?x-screen=1', {ignoreVary: true})]))
                .then(([page, fragment]) => (page && fragment ? page.text() : null))
                .then(html => done(html && html.includes('<meta name="push-identity" content="1">') ? html : null), () => done(null));
            JS;
        $before = self::waitForAsync($paired, [$cache]);
        self::assertStringNotContainsString('data-toast', (string) $before);

        self::editSession('korbo26', static fn (array $bag): array => ['toast' => ['title' => 'Zkušební toast', 'line' => null, 'at' => time()]] + $bag);
        self::visit('/korbo26/profil');
        self::waitFor('const t = document.querySelector("[data-toast]"); return t !== null && t.textContent.includes("Zkušební toast");');

        // the worker stores a page as soon as it has it; give it time to, and look again and again
        $deadline = microtime(true) + 3;
        do {
            $html = self::asyncScript($stored, [$cache]);
            self::assertIsString($html, 'the offline copy of Profil is still there');
            self::assertStringNotContainsString('Zkušební toast', $html);
            self::assertStringNotContainsString('data-toast', $html);
            usleep(250_000);
        } while (microtime(true) < $deadline);
    }
}
