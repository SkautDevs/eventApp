<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The pairing as a reader goes through it: Profil, "Přihlásit kódem z KissJ", the code on
 * the screen, and the page's own poll logging the app in — no reload by hand. The local
 * source approves on the first poll, so the poll is held back in the page until the code
 * has been seen.
 */
#[Group('browser')]
final class PairingFlowTest extends BrowserTestCase
{
    /** Holds every pairing poll of a document until window.__releasePoll is set. */
    private const string HOLD_POLL = <<<'JS'
        (function () {
            var original = window.fetch;
            window.fetch = function (input, init) {
                var url = typeof input === 'string' ? input : (input && input.url) || '';
                if (String(url).indexOf('profil/propojit/stav') === -1 || window.__releasePoll) {
                    return original.apply(this, arguments);
                }
                return new Promise(function (resolve, reject) {
                    var timer = setInterval(function () {
                        if (window.__releasePoll) {
                            clearInterval(timer);
                            original.call(window, input, init).then(resolve, reject);
                        }
                    }, 50);
                });
            };
        })();
        JS;

    public function testTheCodeIsShownAndTheApprovalLogsInWithoutAReload(): void
    {
        // logged out, whatever ran before
        self::visit('/korbo26/');
        self::$browser->manage()->deleteAllCookies();

        $devTools = self::devTools();
        $hold = (string) $devTools->execute('Page.addScriptToEvaluateOnNewDocument', ['source' => self::HOLD_POLL])['identifier'];
        try {
            self::visit('/korbo26/profil');
            self::waitFor('return document.readyState === "complete";');
            self::assertStringContainsString('Nejsi přihlášen', (string) self::script('return document.querySelector(\'[data-screen="/korbo26/profil"]\').textContent;'));
            self::tap('[data-screen="/korbo26/profil"] form[action="/korbo26/profil/propojit"] button.btn-primary');

            self::waitFor('const code = document.querySelector("[data-pairing-poll]"); return code !== null && code.textContent.trim() === "483-291";');
            self::assertSame('Čekám na potvrzení v KissJ…', self::script('return document.querySelector("[data-pairing-status]").textContent.trim();'));
            // drawn, not just present: www/push.js once hid it as one of its own status lines
            self::waitFor('return document.readyState === "complete";');
            self::assertTrue(self::script('const line = document.querySelector("[data-pairing-status]"); return !line.hidden && line.getClientRects().length > 0;'), 'the waiting line is visible');
            self::assertSame(
                ['https://kissj.example/pairing', '_blank'],
                self::script('const a = Array.from(document.querySelectorAll("a.btn-primary")).find(l => l.textContent.includes("Otevřít KissJ")); return [a.href, a.target];'),
            );
            self::script('window.__beforeApproval = 1;');

            // KissJ approves: the page's next poll collects it and reloads Profil by itself
            self::script('window.__releasePoll = true;');
            self::waitFor('const a = document.querySelector(".appbar-avatar"); return document.readyState === "complete" && a !== null && a.textContent.trim() === "JN";', [], 10);
            self::assertNull(self::script('return window.__beforeApproval === undefined ? null : window.__beforeApproval;'), 'the page that showed the code reloaded itself');
            self::assertNotNull(self::script('return document.querySelector(".profile-who");'));
            self::assertNull(self::script('return document.querySelector("[data-pairing-poll]");'));

            // the server's toast on the reloaded Profil, gone again after its timer
            self::assertTrue(self::script('const t = document.querySelector("[data-toast]"); return t !== null && t.textContent.includes("Jsi přihlášen") && t.getClientRects().length > 0;'), 'the login toast is up');
            self::waitFor('return document.querySelector("[data-toast]") === null;', [], 10);
        } finally {
            $devTools->execute('Page.removeScriptToEvaluateOnNewDocument', ['identifier' => $hold]);
        }

        // the participant's programmes are marked on Program
        self::tap('.tabbar a[href="/korbo26/programy"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/programy"]\'); return s && !s.hidden && s.querySelector(".tl-card.is-registered") !== null;');
    }

    /** The logout asks first: the first tap turns the button into the question, the second logs out. */
    public function testTheLogoutTakesASecondTap(): void
    {
        self::pair();
        $button = '[data-screen="/korbo26/profil"] .profile-logout button';
        self::assertSame('Odhlásit se z aplikace', self::script('return document.querySelector(arguments[0]).textContent.trim();', [$button]));

        self::tap($button);
        self::assertSame('Opravdu odhlásit?', self::script('return document.querySelector(arguments[0]).textContent.trim();', [$button]));
        self::assertNotNull(self::script('return document.querySelector(".appbar-avatar");'), 'the first tap logged out');

        self::tap($button);
        self::waitFor('return document.readyState === "complete" && document.querySelector(".appbar-avatar") === null && document.body.textContent.includes("Nejsi přihlášen");');
        self::waitFor('const t = document.querySelector("[data-toast]"); return t !== null && t.textContent.includes("Odhlášeno") && t.textContent.includes("Obsah teď můžeš jen prohlížet");');
    }
}
