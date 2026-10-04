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

    /** Once the browser has let go nothing can arrive, whatever the server does with the POST. */
    public function testTheToggleLetsGoInTheBrowserBeforeTellingTheServer(): void
    {
        $js = self::code();
        $browser = strpos($js, 'if (!await subscription.unsubscribe())');
        $server = strpos($js, "fetch(meta('event-base') + 'push/unsubscribe'");

        self::assertNotFalse($browser);
        self::assertNotFalse($server);
        self::assertLessThan($server, $browser);
        self::assertStringContainsString('localStorage.removeItem(identityKey())', $js);
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

    public function testEveryLineTheOptInShowsIsInTheScript(): void
    {
        $js = self::code();

        foreach ([
            'Aktivuj si notifikace o akci!',
            'Vypnout notifikace',
            'Hotovo! Právě ti přišla uvítací notifikace.',
            'Notifikace máš zapnuté.',
            'Notifikace jsou vypnuté.',
            'Notifikace máš v prohlížeči zakázané. Povol je v nastavení stránky a zkus to znovu.',
            'Notifikace se nepodařilo zapnout. Zkontroluj připojení a zkus to znovu.',
            'Notifikace se nepodařilo vypnout. Zkus to znovu.',
            'Na iPhonu si nejdřív přidej aplikaci na plochu (Sdílet → Přidat na plochu), pak zapneš notifikace.',
        ] as $line) {
            self::assertStringContainsString("'" . $line . "'", $js);
        }
        self::assertStringNotContainsString('jupí', self::script());
        self::assertStringNotContainsString('nepodporuje', self::script());
    }

    public function testTheLayoutShipsTheNewScript(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/_layout.twig');

        self::assertStringContainsString('<script src="push.js?v=10" defer></script>', $layout);
        self::assertSame(1, preg_match_all('/push\.js\?v=\d+/', $layout));
    }
}
