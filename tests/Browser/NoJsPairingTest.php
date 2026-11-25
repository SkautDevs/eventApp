<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * Logging in and out without JavaScript, on korbo26: the pairing wait is the
 * `Zkontrolovat přihlášení` button, which checks the pairing on the server (the local
 * source approves at once), and the logout's two-tap button submits on the first tap,
 * since only a script asks first.
 */
#[Group('browser')]
final class NoJsPairingTest extends NoJsTestCase
{
    private const string SLUG = 'korbo26';

    public function testPairingThroughTheCheckButton(): void
    {
        self::logOut();
        self::visit('/' . self::SLUG . '/profil');
        self::follow('form[action="/' . self::SLUG . '/profil/propojit"] button[type="submit"]');

        // pending: the code, the waiting line and the check button, which a script would hide
        self::assertTrue(self::displayed('[data-pairing-poll]'), 'the code is shown');
        self::assertSame('Čekám na potvrzení v KissJ…', self::text('[data-pairing-status]'));
        self::assertTrue(self::displayed('.pairing-check button'), 'the check button is offered');
        self::assertSame('Zkontrolovat přihlášení', self::text('.pairing-check button'));

        self::pairWithoutScript(self::SLUG);

        self::assertSame('/' . self::SLUG . '/profil', self::path(), 'the check lands on Profil');
        self::assertTrue(self::displayed('.profile-who'));
        self::assertTrue(self::displayed('[data-toast]'), 'the login toast');
        self::assertStringContainsString('Jsi přihlášen', self::text('[data-toast]'));
    }

    public function testLogoutSubmitsOnTheFirstTap(): void
    {
        self::pairWithoutScript(self::SLUG);
        self::assertSame('Opravdu odhlásit?', self::attribute('.profile-logout button', 'data-confirm'));

        // one tap: without a script nothing asks first, the form posts
        self::follow('.profile-logout button');

        self::assertSame('/' . self::SLUG . '/profil', self::path());
        self::assertFalse(self::present('.profile-who'), 'nobody is paired');
        self::assertSame('Nejsi přihlášen', self::text('.login-box h2'));
        self::assertTrue(self::displayed('[data-toast]'), 'the logout toast');
        self::assertStringContainsString('Odhlášeno', self::text('[data-toast]'));
    }

    /** A test that starts logged out: a pairing left by another test is ended through the logout. */
    private static function logOut(): void
    {
        self::visit('/' . self::SLUG . '/profil');
        if (self::present('.profile-logout button')) {
            self::follow('.profile-logout button');
        }
    }
}
