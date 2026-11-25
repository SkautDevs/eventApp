<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The notifications card on Domů and Profil, end to end in Chrome. The browser's push
 * machinery and the two push routes are replaced inside the page: headless Chrome cannot
 * get a real subscription, and a real welcome would leave the machine.
 */
#[Group('browser')]
final class PushToggleTest extends BrowserTestCase
{
    private const string ENABLE = 'Zapnout notifikace';
    private const string TOAST = 'Notifikace zapnuté, jupí!';
    private const string WELCOME = 'Právě ti přišla uvítací notifikace.';

    /** The visible screen's notifications card. */
    private const string CARD = 'document.querySelector(".screen:not([hidden]) [data-push-card]")';

    /** The visible screen's notifications button: the one a tap reaches. */
    private const string BUTTON = '.screen:not([hidden]) [data-push-toggle]';

    /**
     * Replaces the browser's push machinery and the push routes inside the page.
     *
     * It first waits for the page's own start-up to settle, which shows the card (this
     * Chrome is not subscribed), so that start-up cannot run into the fakes; a case that
     * taps the button therefore finds the card shown. With $holdSubscription every
     * getSubscription() waits until window.__releaseSub(), which resolves all the waiting
     * ones; every call after the release answers at once.
     *
     * @param array{status: int, body: array, hold?: bool}|'network' $answer what POST push/subscribe answers
     */
    private static function fake(array|string $answer, bool $subscribed = false, bool $holdSubscription = false): void
    {
        self::waitFor('return document.readyState === "complete";');
        self::waitFor('const card = ' . self::CARD . '; return card !== null && !card.hidden;');
        self::script(<<<'JS'
            const answer = arguments[0];
            const subscribed = arguments[1];
            const holdSubscription = arguments[2];
            window.__calls = {subscribe: 0, unsubscribe: 0, posts: []};
            window.__hold = null;
            const subscription = {
                endpoint: 'https://fcm.googleapis.com/fcm/send/browser-test',
                toJSON() { return {endpoint: this.endpoint, keys: {p256dh: 'test-key', auth: 'test-auth'}}; },
                unsubscribe() { window.__calls.unsubscribe++; window.__sub = null; return Promise.resolve(true); },
            };
            window.__sub = subscribed ? subscription : null;
            Notification.requestPermission = () => Promise.resolve('granted');
            Object.defineProperty(Notification, 'permission', {get: () => 'granted', configurable: true});
            PushManager.prototype.subscribe = function () { window.__calls.subscribe++; window.__sub = subscription; return Promise.resolve(subscription); };
            const held = [];
            let released = !holdSubscription;
            window.__releaseSub = () => {
                released = true;
                held.splice(0).forEach(resolve => resolve(window.__sub));
            };
            PushManager.prototype.getSubscription = function () {
                if (released) {
                    return Promise.resolve(window.__sub);
                }
                return new Promise(resolve => { held.push(resolve); });
            };
            const realFetch = window.fetch.bind(window);
            window.fetch = function (url, options) {
                const path = String(url);
                if (!/push\/(un)?subscribe$/.test(path)) {
                    return realFetch(url, options);
                }
                window.__calls.posts.push(path.endsWith('unsubscribe') ? 'unsubscribe' : 'subscribe');
                if (path.endsWith('unsubscribe')) {
                    return Promise.resolve(new Response(null, {status: 204}));
                }
                const reply = () => (answer === 'network'
                    ? Promise.reject(new TypeError('Failed to fetch'))
                    : Promise.resolve(new Response(JSON.stringify(answer.body), {status: answer.status, headers: {'Content-Type': 'application/json'}})));
                if (answer !== 'network' && answer.hold) {
                    return new Promise(resolve => { window.__hold = () => resolve(reply()); });
                }
                return reply();
            };
            JS, [$answer, $subscribed, $holdSubscription]);
    }

    /**
     * Marks the page's push identity as already sent, so the start-up's re-sync makes no
     * getSubscription() call of its own and only the detection's waits on a hold.
     */
    private static function identitySent(): void
    {
        self::script("localStorage.setItem('pushIdentity:/korbo26/', document.querySelector('meta[name=\"push-identity\"]').content);");
    }

    private static function waitForStatus(string $text): void
    {
        self::waitFor('const s = document.querySelector(".screen:not([hidden]) .push-status"); return !s.hidden && s.textContent === arguments[0];', [$text]);
    }

    /**
     * What the visible screen shows: the card, its button, its status line (null: hidden)
     * and Profil's quiet line (null: not on this screen).
     *
     * @return array{card: bool, button: bool, status: ?string, on: ?bool}
     */
    private static function card(): array
    {
        $state = self::script(<<<'JS'
            const screen = document.querySelector('.screen:not([hidden])');
            const card = screen.querySelector('[data-push-card]');
            const status = card.querySelector('.push-status');
            const on = screen.querySelector('[data-push-on]');
            return {
                card: !card.hidden,
                button: !card.querySelector('.push-enable').hidden,
                status: status.hidden ? null : status.textContent,
                on: on ? !on.hidden : null,
            };
            JS);

        // chromedriver hands an object back with its keys sorted, so the order is set here
        return ['card' => $state['card'], 'button' => $state['button'], 'status' => $state['status'], 'on' => $state['on']];
    }

    /** @return array{title: string, line: ?string}|null the toast on show, null when there is none */
    private static function toast(): ?array
    {
        $toast = self::script(<<<'JS'
            const toast = document.querySelector('[data-toast]');
            if (!toast) {
                return null;
            }
            const line = toast.querySelector('span');
            return {title: toast.querySelector('strong').textContent, line: line ? line.textContent : null};
            JS);

        return $toast === null ? null : ['title' => $toast['title'], 'line' => $toast['line']];
    }

    private static function waitForToast(): void
    {
        self::waitFor('return document.querySelector("[data-toast]") !== null;');
    }

    /**
     * A real tap on the visible screen's button. The card sits under the install card, low
     * on a phone's screen, so it is first brought to the middle, clear of the tab bar.
     */
    private static function tapButton(): void
    {
        self::script('document.querySelector(arguments[0]).scrollIntoView({block: "center"});', [self::BUTTON]);
        self::tap(self::BUTTON);
    }

    /** Re-runs www/js/push/'s start-up (re-sync, then detection) with the fakes in place. */
    private static function restart(): void
    {
        self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            navigator.serviceWorker.ready.then(() => { document.dispatchEvent(new Event('DOMContentLoaded')); done(true); });
            JS);
    }

    public function testWithoutPushManagerTheCardStaysHidden(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        self::script("delete window.PushManager; document.querySelector('.screen:not([hidden])').dispatchEvent(new CustomEvent('screen:shown', {bubbles: true}));");

        self::assertSame(['card' => false, 'button' => false, 'status' => null, 'on' => null], self::card());
    }

    public function testOnIosTheCardSaysToSaveTheAppFirst(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        self::script("Object.defineProperty(navigator, 'userAgent', {get: () => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', configurable: true}); delete window.PushManager; document.querySelector('.screen:not([hidden])').dispatchEvent(new CustomEvent('screen:shown', {bubbles: true}));");

        self::waitForStatus('Na iPhonu zapneš notifikace, až si aplikaci přidáš na plochu.');
        self::assertSame(['card' => true, 'button' => false, 'status' => 'Na iPhonu zapneš notifikace, až si aplikaci přidáš na plochu.', 'on' => null], self::card());
    }

    public function testTheCardShowsOnceTheBrowserSaysItIsNotSubscribed(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => true]], subscribed: false, holdSubscription: true);
        self::identitySent();

        self::restart();

        self::assertFalse(self::card()['card']);
        self::script('window.__releaseSub();');
        self::waitFor('return !' . self::CARD . '.hidden;');
        self::assertSame(['card' => true, 'button' => true, 'status' => null, 'on' => null], self::card());
        self::assertSame(self::ENABLE, self::script('return document.querySelector(arguments[0]).textContent;', [self::BUTTON]));
    }

    /**
     * Review Focus 1: from the start-up on, a subscribed reader's card is never unhidden,
     * not even for a frame, while the subscribed branch does run (Profil's line shows).
     */
    public function testASubscribedReaderNeverSeesTheCard(): void
    {
        self::visit('/korbo26/profil');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => true]], subscribed: true, holdSubscription: true);
        self::identitySent();
        // Each record's new value is the next record's old one, the last's the attribute now:
        // a record whose new value is "no hidden" is one moment the card was on show.
        self::script(<<<'JS'
            const card = document.querySelector('.screen:not([hidden]) [data-push-card]');
            window.__shown = 0;
            new MutationObserver(records => {
                const hidden = records.filter(record => record.attributeName === 'hidden');
                hidden.forEach((record, i) => {
                    const next = i + 1 < hidden.length ? hidden[i + 1].oldValue : card.getAttribute('hidden');
                    if (next === null) {
                        window.__shown++;
                    }
                });
            }).observe(card, {attributes: true, attributeOldValue: true});
            JS);

        self::restart();

        self::assertFalse(self::card()['card']);
        self::script('window.__releaseSub(); setTimeout(() => { window.__settled = true; }, 300);');
        self::waitFor('return window.__settled === true;');
        self::waitFor('return !document.querySelector(".screen:not([hidden]) [data-push-on]").hidden;');
        self::assertSame(0, self::script('return window.__shown;'));
        self::assertSame(['card' => false, 'button' => false, 'status' => null, 'on' => true], self::card());
    }

    /** There is no off switch: subscribing hides the card for good and says so in a toast. */
    public function testSubscribingShowsTheToastAndHidesTheCard(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => true]]);

        self::tapButton();
        self::waitForToast();

        self::assertSame(['title' => self::TOAST, 'line' => self::WELCOME], self::toast());
        self::assertFalse(self::card()['card']);
        self::assertSame(['subscribe'], self::script('return window.__calls.posts;'));
        self::assertSame(0, self::script('return window.__calls.unsubscribe;'));
    }

    /** Carry-over: a welcome that did not arrive is never a warning, only the truth. */
    public function testAWelcomeThatDidNotArriveLeavesTheSecondLineOut(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => false]]);

        self::tapButton();
        self::waitForToast();

        self::assertSame(['title' => self::TOAST, 'line' => null], self::toast());
        self::assertFalse(self::card()['card']);
    }

    public function testOnProfilASubscribedReaderSeesTheQuietLine(): void
    {
        self::visit('/korbo26/profil');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => false]], subscribed: true);

        self::restart();

        self::waitFor('return !document.querySelector(".screen:not([hidden]) [data-push-on]").hidden;');
        self::assertSame('Notifikace máš zapnuté.', self::script('return document.querySelector(".screen:not([hidden]) [data-push-on]").textContent;'));
        self::assertSame(['card' => false, 'button' => false, 'status' => null, 'on' => true], self::card());
    }

    public function testSubscribingOnProfilShowsTheQuietLine(): void
    {
        self::visit('/korbo26/profil');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => true]]);

        self::tapButton();
        self::waitForToast();

        self::assertTrue(self::card()['on']);
        self::assertFalse(self::card()['card']);
    }

    public function testARejectedSubscriptionKeepsTheButtonAndDropsTheHalfSubscription(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 502, 'body' => ['saved' => false, 'welcome' => false, 'error' => 'subscription-rejected']]);

        self::tapButton();

        self::waitForStatus('Notifikace se nepodařilo zapnout. Zkontroluj připojení a zkus to znovu.');
        self::assertSame(['card' => true, 'button' => true, 'status' => 'Notifikace se nepodařilo zapnout. Zkontroluj připojení a zkus to znovu.', 'on' => null], self::card());
        self::assertSame(1, self::script('return window.__calls.unsubscribe;'));
    }

    /** FF-I2: a camp behind one address hit the limit — say so, and keep the browser's subscription for the retry. */
    public function testTooManySaysSoAndKeepsTheBrowserSubscription(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 429, 'body' => ['saved' => false, 'error' => 'too-many']]);

        self::tapButton();

        self::waitForStatus('Teď si notifikace zapíná moc lidí najednou, zkus to za chvíli.');
        self::assertSame(['card' => true, 'button' => true, 'status' => 'Teď si notifikace zapíná moc lidí najednou, zkus to za chvíli.', 'on' => null], self::card());
        self::assertSame(0, self::script('return window.__calls.unsubscribe;'));
        self::assertTrue(self::script('return window.__sub !== null;'));
    }

    public function testAnInvalidKeySaysSoAndDropsTheHalfSubscription(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 400, 'body' => ['saved' => false, 'error' => 'invalid-key']]);

        self::tapButton();

        self::waitForStatus('Prohlížeč poslal neplatné údaje, zkus notifikace zapnout znovu.');
        self::assertSame(['card' => true, 'button' => true, 'status' => 'Prohlížeč poslal neplatné údaje, zkus notifikace zapnout znovu.', 'on' => null], self::card());
        self::assertSame(1, self::script('return window.__calls.unsubscribe;'));
    }

    /** A morph that shows the card again is drawn over: www/js/push/ hides it once more. */
    public function testAMorphKeepsTheCardHiddenAfterSubscribing(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => true]]);
        self::tapButton();
        self::waitForToast();

        self::script(<<<'JS'
            const card = document.querySelector('.screen:not([hidden]) [data-push-card]');
            card.hidden = false;
            card.closest('[data-screen]').dispatchEvent(new CustomEvent('screen:morphed', {bubbles: true}));
            JS);

        self::assertFalse(self::card()['card']);
    }

    public function testADoubleTapSubscribesOnce(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => true], 'hold' => true]);

        // the first click disables the button synchronously; the second lands on a disabled one
        self::script('const b = document.querySelector(arguments[0]); b.click(); b.click();', [self::BUTTON]);
        self::waitFor('return typeof window.__hold === "function";');
        self::assertTrue(self::script('return document.querySelector(arguments[0]).disabled;', [self::BUTTON]));
        self::script('window.__hold();');

        self::waitForToast();
        self::assertSame(1, self::script('return window.__calls.subscribe;'));
        self::assertSame(['subscribe'], self::script('return window.__calls.posts;'));
    }

    /** Carry-over: the re-sync after a login found the subscription dead — the browser lets go too. */
    public function testARejectedResyncTurnsTheBrowserOff(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 502, 'body' => ['saved' => false, 'welcome' => false, 'error' => 'subscription-rejected']], subscribed: true);
        self::script("localStorage.setItem('pushIdentity:/korbo26/', '999');");

        self::restart();

        self::waitForStatus('Notifikace jsou vypnuté.');
        self::assertSame(['card' => true, 'button' => true, 'status' => 'Notifikace jsou vypnuté.', 'on' => null], self::card());
        self::assertSame(1, self::script('return window.__calls.unsubscribe;'));
        self::assertNull(self::script("return localStorage.getItem('pushIdentity:/korbo26/');"));
    }

    /** A network error or a 500 proves nothing about the subscription. */
    public function testANetworkErrorOrA500OnResyncKeepsTheSubscription(): void
    {
        foreach (['network', ['status' => 500, 'body' => []]] as $answer) {
            self::visit('/korbo26/profil');
            self::fake($answer, subscribed: true);
            self::script("localStorage.setItem('pushIdentity:/korbo26/', '999');");

            self::restart();

            // the re-sync answered; the detection after it found the subscription kept, so
            // Profil's quiet line shows (the card is hidden right after restart() either way)
            self::waitFor('return window.__calls.posts.length === 1 && !document.querySelector(".screen:not([hidden]) [data-push-on]").hidden;');
            self::assertSame(['subscribe'], self::script('return window.__calls.posts;'), json_encode($answer) ?: '');
            self::assertSame(0, self::script('return window.__calls.unsubscribe;'), json_encode($answer) ?: '');
            self::assertFalse(self::card()['card'], json_encode($answer) ?: '');
        }
    }
}
