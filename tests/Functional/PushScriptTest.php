<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * The opt-in's behaviour is proved in a browser (Round C). What a test can hold here is
 * the script's contract: no alert(), the order of the unsubscribe, the answer keys it
 * reads, every line it can show, and the cache-buster that ships it.
 */
final class PushScriptTest extends TestCase
{
    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/www/push.js');
    }

    /**
     * The script with every whole-line `//` comment dropped, so a literal that survives
     * only in a comment does not satisfy (or break) a check meant for the code.
     */
    private static function code(): string
    {
        $lines = preg_split('/\R/', self::script()) ?: [];

        return implode("\n", array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '//')));
    }

    public function testThereIsNoAlertLeft(): void
    {
        self::assertDoesNotMatchRegularExpression('/\balert\s*\(/', self::script());
    }

    /** There is no off switch: once subscribed, the button is hidden and only the browser's settings turn it off. */
    public function testTheOptInCannotTurnNotificationsOff(): void
    {
        $js = self::code();

        self::assertStringNotContainsString('push/unsubscribe', $js);
        self::assertStringNotContainsString('disablePush', $js);
        self::assertStringContainsString('el.hidden = !ok || subscribed;', $js);
    }

    public function testTheSubscribeAnswerIsReadBySavedAndWelcome(): void
    {
        $js = self::code();

        self::assertStringContainsString('result.saved !== true', $js);
        self::assertStringContainsString('result.welcome === true', $js);
        // the body key Task 7 removed is not read any more
        self::assertDoesNotMatchRegularExpression('/\bresult\.ok\b/', $js);
        // the ManifestTest and CspTest contract strings survive the rewrite
        self::assertStringContainsString("fetch(meta('event-base') + 'push/subscribe'", $js);
        self::assertStringContainsString("querySelectorAll('[data-push-toggle]')", $js);
    }

    public function testSubscribingWaitsForAnActiveWorker(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/www/push.js');
        self::assertStringContainsString("preparing: 'Připravuji…',", $js);
        self::assertStringContainsString('navigator.serviceWorker.ready,', $js);
        self::assertStringContainsString('const READY_TIMEOUT = 10000;', $js);
        self::assertStringContainsString('const registration = await workerReady();', $js);
        // the deadline is cleared once the worker is ready
        self::assertStringContainsString('.finally(() => clearTimeout(timer));', $js);
    }

    public function testEveryLineTheOptInShowsIsInTheScript(): void
    {
        $js = self::code();

        foreach ([
            'Aktivuj si notifikace o akci!',
            'Hotovo! Právě ti přišla uvítací notifikace.',
            'Notifikace máš zapnuté, jupí!',
            'Notifikace jsou vypnuté.',
            'Notifikace máš v prohlížeči zakázané. Povol je v nastavení stránky a zkus to znovu.',
            'Notifikace se nepodařilo zapnout. Zkontroluj připojení a zkus to znovu.',
            'Teď si notifikace zapíná moc lidí najednou, zkus to za chvíli.',
            'Prohlížeč poslal neplatné údaje, zkus notifikace zapnout znovu.',
            'Na iPhonu si nejdřív přidej aplikaci na plochu (Sdílet → Přidat na plochu), pak zapneš notifikace.',
            'Připravuji…',
        ] as $line) {
            self::assertStringContainsString("'" . $line . "'", $js);
        }
        self::assertStringNotContainsString('Vypnout notifikace', self::script());
        self::assertStringNotContainsString('nepodporuje', self::script());
    }

    /** Only the push service's own refusal proves a subscription dead. */
    public function testARejectedResyncLetsGoOfTheBrowserSubscription(): void
    {
        $js = self::code();

        self::assertStringContainsString("error.rejected = response.status === 502 && result.error === 'subscription-rejected';", $js);
        self::assertStringContainsString('if (e.rejected === true) {', $js);
        // enablePush() drops half a subscription, syncIdentity() a rejected one
        self::assertSame(2, substr_count($js, 'await subscription.unsubscribe().catch(() => {});'));
    }

    /** FF-I2: a 429 is the address's limit, not the subscription's fault, and keeps it; a 400 invalid-key has its own line. */
    public function testTooManyAndAnInvalidKeyHaveTheirOwnLines(): void
    {
        $js = self::code();

        self::assertStringContainsString('error.tooMany = response.status === 429;', $js);
        self::assertStringContainsString("error.invalidKey = response.status === 400 && result.error === 'invalid-key';", $js);
        // the 429 branch returns before the half-subscription is dropped
        $tooMany = strpos($js, 'if (e.tooMany === true) {');
        self::assertNotFalse($tooMany);
        self::assertLessThan(strpos($js, 'await subscription.unsubscribe().catch(() => {});', $tooMany), strpos($js, 'status = TEXT.tooMany;', $tooMany));
        self::assertStringContainsString('status = e.invalidKey === true ? TEXT.invalidKey : TEXT.enableFailed;', $js);
    }

    public function testTheSubscriptionIsDetectedAfterTheResync(): void
    {
        self::assertStringContainsString('syncIdentity().catch(() => {}).then(() => detectSubscription()).catch(() => {});', self::code());
    }

    public function testTheLayoutShipsTheNewScript(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/_layout.twig');

        self::assertStringContainsString('<script src="{{ asset_version(\'push.js\') }}" defer></script>', $layout);
        self::assertSame(1, substr_count($layout, "asset_version('push.js')"));
    }
}
