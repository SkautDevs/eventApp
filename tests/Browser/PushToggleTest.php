<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The notification opt-in on the homepage, end to end in Chrome. The browser's push
 * machinery and the two push routes are replaced inside the page: headless Chrome cannot
 * get a real subscription, and a real welcome would leave the machine.
 */
#[Group('browser')]
final class PushToggleTest extends BrowserTestCase
{
    private const string ENABLE = 'Aktivuj si notifikace o akci!';
    private const string ON = 'Notifikace máš zapnuté, jupí!';

    /**
     * @param array{status: int, body: array, hold?: bool}|'network' $answer what POST push/subscribe answers
     */
    private static function fake(array|string $answer, bool $subscribed = false): void
    {
        self::waitFor('return document.readyState === "complete";');
        self::script(<<<'JS'
            const answer = arguments[0];
            const subscribed = arguments[1];
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
            PushManager.prototype.getSubscription = function () { return Promise.resolve(window.__sub); };
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
            JS, [$answer, $subscribed]);
    }

    private static function waitForStatus(string $text): void
    {
        self::waitFor('const s = document.querySelector(".push-status"); return !s.hidden && s.textContent === arguments[0];', [$text]);
    }

    private static function button(): array
    {
        $state = self::script('const b = document.querySelector("[data-push-toggle]"); return {label: b.textContent.trim(), primary: b.classList.contains("btn-primary"), visible: !b.closest(".push-enable").hidden, disabled: b.disabled};');

        // chromedriver hands an object back with its keys sorted, so the order is set here
        return ['label' => $state['label'], 'primary' => $state['primary'], 'visible' => $state['visible'], 'disabled' => $state['disabled']];
    }

    /** Re-runs push.js's start-up (re-sync, then detection) with the fakes in place. */
    private static function restart(): void
    {
        self::asyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            navigator.serviceWorker.ready.then(() => { document.dispatchEvent(new Event('DOMContentLoaded')); done(true); });
            JS);
    }

    public function testWithoutPushManagerTheButtonIsHidden(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        self::script("delete window.PushManager; document.querySelector('[data-screen]').dispatchEvent(new CustomEvent('screen:shown', {bubbles: true}));");

        self::assertTrue(self::script('return document.querySelector(".push-enable").hidden && document.querySelector(".push-status").hidden;'));
    }

    public function testOnIosTheHintReplacesTheButton(): void
    {
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        self::script("Object.defineProperty(navigator, 'userAgent', {get: () => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', configurable: true}); delete window.PushManager; document.querySelector('[data-screen]').dispatchEvent(new CustomEvent('screen:shown', {bubbles: true}));");

        self::waitForStatus('Na iPhonu zapneš notifikace, až si aplikaci přidáš na plochu.');
        self::assertTrue(self::script('return document.querySelector(".push-enable").hidden;'));
    }

    /** There is no off switch: subscribing hides the button for good. */
    public function testSubscribingHidesTheButton(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => true]]);

        self::tap('[data-push-toggle]');
        self::waitForStatus('Hotovo! Právě ti přišla uvítací notifikace.');
        self::assertSame(['label' => self::ENABLE, 'primary' => true, 'visible' => false, 'disabled' => false], self::button());
        self::assertSame(['subscribe'], self::script('return window.__calls.posts;'));
        self::assertSame(0, self::script('return window.__calls.unsubscribe;'));
    }

    /** Carry-over: a welcome that did not arrive is never a warning, only the truth. */
    public function testAWelcomeThatDidNotArriveSaysOnlyThatItIsOn(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => false]]);

        self::tap('[data-push-toggle]');

        self::waitForStatus(self::ON);
        self::assertFalse(self::button()['visible']);
    }

    public function testARejectedSubscriptionKeepsTheButtonAndDropsTheHalfSubscription(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 502, 'body' => ['saved' => false, 'welcome' => false, 'error' => 'subscription-rejected']]);

        self::tap('[data-push-toggle]');

        self::waitForStatus('Notifikace se nepodařilo zapnout. Zkontroluj připojení a zkus to znovu.');
        self::assertSame(['label' => self::ENABLE, 'primary' => true, 'visible' => true, 'disabled' => false], self::button());
        self::assertSame(1, self::script('return window.__calls.unsubscribe;'));
    }

    /** FF-I2: a camp behind one address hit the limit — say so, and keep the browser's subscription for the retry. */
    public function testTooManySaysSoAndKeepsTheBrowserSubscription(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 429, 'body' => ['saved' => false, 'error' => 'too-many']]);

        self::tap('[data-push-toggle]');

        self::waitForStatus('Teď si notifikace zapíná moc lidí najednou, zkus to za chvíli.');
        self::assertSame(['label' => self::ENABLE, 'primary' => true, 'visible' => true, 'disabled' => false], self::button());
        self::assertSame(0, self::script('return window.__calls.unsubscribe;'));
        self::assertTrue(self::script('return window.__sub !== null;'));
    }

    public function testAnInvalidKeySaysSoAndDropsTheHalfSubscription(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 400, 'body' => ['saved' => false, 'error' => 'invalid-key']]);

        self::tap('[data-push-toggle]');

        self::waitForStatus('Prohlížeč poslal neplatné údaje, zkus notifikace zapnout znovu.');
        self::assertSame(['label' => self::ENABLE, 'primary' => true, 'visible' => true, 'disabled' => false], self::button());
        self::assertSame(1, self::script('return window.__calls.unsubscribe;'));
    }

    /** The morph writes the server's hidden back; push.js hides the button again. */
    public function testAMorphPutsTheButtonStateBack(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => true]]);
        self::tap('[data-push-toggle]');
        self::waitForStatus('Hotovo! Právě ti přišla uvítací notifikace.');

        self::script(<<<'JS'
            const button = document.querySelector('[data-push-toggle]');
            button.closest('.push-enable').hidden = false;
            button.closest('[data-screen]').dispatchEvent(new CustomEvent('screen:morphed', {bubbles: true}));
            JS);

        self::assertFalse(self::button()['visible']);
    }

    public function testADoubleTapSubscribesOnce(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 201, 'body' => ['saved' => true, 'welcome' => true], 'hold' => true]);

        // the first click disables the button synchronously; the second lands on a disabled one
        self::script('const b = document.querySelector("[data-push-toggle]"); b.click(); b.click();');
        self::waitFor('return typeof window.__hold === "function";');
        self::assertTrue(self::button()['disabled']);
        self::script('window.__hold();');

        self::waitForStatus('Hotovo! Právě ti přišla uvítací notifikace.');
        self::assertSame(1, self::script('return window.__calls.subscribe;'));
        self::assertSame(['subscribe'], self::script('return window.__calls.posts;'));
    }

    /** Carry-over: the re-sync after a login found the subscription dead — the browser lets go too. */
    public function testARejectedResyncTurnsTheBrowserOff(): void
    {
        self::visit('/korbo26/');
        self::fake(['status' => 502, 'body' => ['saved' => false, 'welcome' => false, 'error' => 'subscription-rejected']], subscribed: true);
        self::script("localStorage.setItem('pushIdentity:/korbo26/', 'TIE SOMEONE-ELSE');");

        self::restart();

        self::waitForStatus('Notifikace jsou vypnuté.');
        self::assertSame(1, self::script('return window.__calls.unsubscribe;'));
        self::assertTrue(self::button()['visible']);
        self::assertNull(self::script("return localStorage.getItem('pushIdentity:/korbo26/');"));
    }

    /** A network error or a 500 proves nothing about the subscription. */
    public function testANetworkErrorOrA500OnResyncKeepsTheSubscription(): void
    {
        foreach (['network', ['status' => 500, 'body' => []]] as $answer) {
            self::visit('/korbo26/');
            self::fake($answer, subscribed: true);
            self::script("localStorage.setItem('pushIdentity:/korbo26/', 'TIE SOMEONE-ELSE');");

            self::restart();

            self::waitForStatus(self::ON);
            self::assertSame(0, self::script('return window.__calls.unsubscribe;'), json_encode($answer));
            self::assertFalse(self::button()['visible']);
        }
    }
}
