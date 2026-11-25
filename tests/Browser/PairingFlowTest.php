<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The pairing as a reader goes through it: Profil, "Přihlásit kódem z KissJ", the code on
 * the screen, and the page's own poll logging the app in and landing where the poll's
 * `next` says (Moje, Přehled, or the sheet the login started from) — no reload by hand,
 * and no history entry of its own: the start and the approval each replace Profil's. The
 * local source approves on the first poll, so the poll is held back in the page until the
 * code has been seen.
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

    public function testTheCodeIsShownAndTheApprovalLandsOnMoje(): void
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

            self::waitFor('const code = document.querySelector("[data-pairing-poll]"); return code !== null && code.textContent.trim() === "483 291";');
            self::assertSame('Čekám na potvrzení v KissJ…', self::script('return document.querySelector("[data-pairing-status]").textContent.trim();'));
            // drawn, not just present: the push script once hid it as one of its own status lines
            self::waitFor('return document.readyState === "complete";');
            self::assertTrue(self::script('const line = document.querySelector("[data-pairing-status]"); return !line.hidden && line.getClientRects().length > 0;'), 'the waiting line is visible');
            self::assertSame(
                ['https://kissj.example/pairing', '_blank'],
                self::script('const a = Array.from(document.querySelectorAll("a.btn-primary")).find(l => l.textContent.includes("Otevřít KissJ")); return [a.href, a.target];'),
            );
            self::script('window.__beforeApproval = 1;');

            // KissJ approves: the page's next poll collects it and lands on the poll's next, Moje
            self::script('window.__releasePoll = true;');
            self::waitFor('return document.readyState === "complete" && location.pathname === "/korbo26/programy/moje";', [], 10);
            self::assertNull(self::script('return window.__beforeApproval === undefined ? null : window.__beforeApproval;'), 'the landing is a new document');
            self::waitFor('const a = document.querySelector(".tab-avatar"); return a !== null && a.textContent.trim() === "JN";');
            self::assertSame('list', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
            self::assertTrue(self::script('const t = document.querySelector("[data-toast]"); return t !== null && t.textContent.includes("Jsi přihlášen");'), 'the login toast is on the landing');
        } finally {
            $devTools->execute('Page.removeScriptToEvaluateOnNewDocument', ['identifier' => $hold]);
        }

        // the participant's programmes are marked
        self::waitFor('return document.querySelector(".pl-item.is-registered") !== null;');
    }

    /** A participant with no programmes lands on Přehled. */
    public function testALoginWithoutProgrammesLandsOnPrehled(): void
    {
        self::resetStubState();
        self::writeStubRegistrations('korbo26', ['1' => []]);
        try {
            self::visit('/korbo26/');
            self::$browser->manage()->deleteAllCookies();
            self::visit('/korbo26/profil');
            self::waitFor('return document.readyState === "complete";');
            self::tap('[data-screen="/korbo26/profil"] form[action="/korbo26/profil/propojit"] button.btn-primary');

            self::waitFor('return document.readyState === "complete" && location.pathname === "/korbo26/programy/prehled";', [], 20);
            self::assertSame('overview', self::script('return document.querySelector("[data-pg-root]").dataset.view;'));
            self::assertTrue(self::script('const t = document.querySelector("[data-toast]"); return t !== null && t.textContent.includes("Jsi přihlášen");'), 'the login toast is on the landing');
        } finally {
            self::resetStubState();
        }
    }

    /** Review Focus 3: a login started from a programme's sheet comes back to that sheet. */
    public function testALoginFromASheetComesBackToIt(): void
    {
        self::visit('/korbo26/');
        self::$browser->manage()->deleteAllCookies();
        self::visit('/korbo26/programy#section-1-program-1');
        self::waitFor('return document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="1"]\') !== null;');
        self::script('window.__sheet = 1;');

        self::tap('.sheet-body.is-open .sheet-footer a[data-key="unpaired"]');
        self::waitFor('return location.pathname === "/korbo26/profil" && location.search === "?program=1" && window.__sheet === undefined && document.readyState === "complete";');
        $start = '[data-screen="/korbo26/profil"] form[action="/korbo26/profil/propojit"]';
        self::assertNotNull(self::script('return document.querySelector(arguments[0] + \' input[name="program"][value="1"]\');', [$start]), 'the start form carries the programme');

        self::tap($start . ' button.btn-primary');
        self::waitFor('return document.readyState === "complete" && location.pathname === "/korbo26/programy" && location.hash === "#section-1-program-1" && document.querySelector(\'.sheet.is-open .sheet-body.is-open[data-pg-detail="1"]\') !== null;', [], 20);
        self::assertTrue(self::script('const t = document.querySelector("[data-toast]"); return t !== null && t.textContent.includes("Jsi přihlášen");'), 'the login toast is over the sheet');
    }

    /**
     * The return target belongs to the address that named it: Profil opened from a sheet
     * (`?program=1`) and then left, comes back by its tab without the programme.
     */
    public function testATabVisitToProfilCarriesNoOldReturnTarget(): void
    {
        self::visit('/korbo26/');
        self::$browser->manage()->deleteAllCookies();
        self::visit('/korbo26/profil?program=1');
        self::waitFor('return document.readyState === "complete";');
        $field = '[data-screen="/korbo26/profil"] form[action="/korbo26/profil/propojit"] input[name="program"]';
        self::assertSame('1', self::script('const f = document.querySelector(arguments[0]); return f === null ? null : f.value;', [$field]));

        self::tap('.tabbar a[href="/korbo26/"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/"]\'); return s !== null && !s.hidden && location.pathname === "/korbo26/";');
        self::tap('.tabbar a[href="/korbo26/profil"]');
        // the copy served for ?program=1 is on show at once, then revalidated for the plain address
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/profil"]\'); return s !== null && !s.hidden && location.pathname === "/korbo26/profil" && s.querySelector(\'form[action="/korbo26/profil/propojit"]\') !== null && document.querySelector(arguments[0]) === null;', [$field]);
        self::assertSame('', self::script('return location.search;'));
        self::assertNull(self::script('const f = document.querySelector(arguments[0]); return f === null ? null : f.value;', [$field]), 'the Profil tab brought back the old return target');
    }

    /**
     * Back to the entry that named the target brings it back: the `profil?program=1` entry,
     * after a tab visit dropped the field, shows the start form with `program` = 1 again.
     */
    public function testBackToTheQueriedProfilEntryBringsItsReturnTargetBack(): void
    {
        self::visit('/korbo26/');
        self::$browser->manage()->deleteAllCookies();
        self::visit('/korbo26/profil?program=1');
        self::waitFor('return document.readyState === "complete";');
        $profil = '[data-screen="/korbo26/profil"]';
        $field = $profil . ' form[action="/korbo26/profil/propojit"] input[name="program"]';
        self::assertSame('1', self::script('const f = document.querySelector(arguments[0]); return f === null ? null : f.value;', [$field]));
        self::script('window.__noReload = 1;');

        self::tap('.tabbar a[href="/korbo26/"]');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/"]\'); return s !== null && !s.hidden && location.pathname === "/korbo26/";');
        self::tap('.tabbar a[href="/korbo26/profil"]');
        self::waitFor('const s = document.querySelector(arguments[0]); return s !== null && !s.hidden && location.pathname === "/korbo26/profil" && document.querySelector(arguments[1]) === null;', [$profil, $field]);

        self::script('history.back();');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/"]\'); return s !== null && !s.hidden && location.pathname === "/korbo26/";');
        self::script('history.back();');
        self::waitFor('const s = document.querySelector(arguments[0]); return s !== null && !s.hidden && location.pathname === "/korbo26/profil" && location.search === "?program=1";', [$profil]);
        self::waitFor('const f = document.querySelector(arguments[0]); return f !== null && f.value === "1";', [$field]);
        self::assertSame(1, self::script('return window.__noReload;'), 'Back loaded a document');
    }

    /**
     * The start and the approval each replace their entry: Back from the landing goes to
     * the screen before Profil, never to a Profil still showing a code.
     */
    public function testTheApprovalReplacesTheWaitingProfil(): void
    {
        // logged out, whatever ran before
        self::visit('/korbo26/');
        self::$browser->manage()->deleteAllCookies();
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete" && document.querySelector(".tab-avatar") === null;');

        self::tap('.tabbar a[href="/korbo26/profil"]');
        $start = '[data-screen="/korbo26/profil"] form[action="/korbo26/profil/propojit"] button.btn-primary';
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/profil"]\'); return s !== null && !s.hidden && document.querySelector(arguments[0]) !== null;', [$start]);
        self::assertSame('/korbo26/profil', self::script('return location.pathname;'));
        $n = self::script('return history.length;');

        self::tap($start);
        self::waitFor('return document.readyState === "complete" && location.pathname === "/korbo26/programy/moje";', [], 20);
        self::assertSame($n, self::script('return history.length;'), 'the start or the approval added a history entry');

        self::script('history.back();');
        self::waitFor('return location.pathname === "/korbo26/" && document.readyState === "complete";');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/"]\'); return s !== null && !s.hidden;');
        self::assertNull(self::script('return document.querySelector("[data-pairing-poll]");'), 'Back showed a Profil with a code');
    }

    /** The logout asks first: the first tap turns the button into the question, the second logs out. */
    public function testTheLogoutTakesASecondTap(): void
    {
        self::pair();
        self::visit('/korbo26/profil');
        self::waitFor('return document.readyState === "complete";');
        $button = '[data-screen="/korbo26/profil"] .profile-logout button';
        self::assertSame('Odhlásit se z aplikace', self::script('return document.querySelector(arguments[0]).textContent.trim();', [$button]));

        self::tap($button);
        self::assertSame('Opravdu odhlásit?', self::script('return document.querySelector(arguments[0]).textContent.trim();', [$button]));
        self::assertNotNull(self::script('return document.querySelector(".tab-avatar");'), 'the first tap logged out');

        self::tap($button);
        self::waitFor('return document.readyState === "complete" && document.querySelector(".tab-avatar") === null && document.body.textContent.includes("Nejsi přihlášen");');
        self::waitFor('const t = document.querySelector("[data-toast]"); return t !== null && t.textContent.includes("Odhlášeno") && t.textContent.includes("Obsah teď můžeš jen prohlížet");');
    }
}
