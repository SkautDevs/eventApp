<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * Logging in and out without JavaScript, on korbo26: the pairing wait is the
 * `Zkontrolovat přihlášení` button, which checks the pairing on the server (the local
 * source approves at once) and redirects to the approval's landing on Program, and the
 * logout's two-tap button submits on the first tap, since only a script asks first. Every
 * test starts logged out, with the fixture participant's own programmes.
 */
#[Group('browser')]
final class NoJsPairingTest extends NoJsTestCase
{
    private const string SLUG = 'korbo26';

    protected function setUp(): void
    {
        parent::setUp();
        // logged out, whatever ran before
        self::visit('/' . self::SLUG . '/');
        self::$browser->manage()->deleteAllCookies();
        self::resetStubState(self::SLUG);
    }

    public function testPairingThroughTheCheckButton(): void
    {
        self::visit('/' . self::SLUG . '/profil');
        self::follow('form[action="/' . self::SLUG . '/profil/propojit"] button[type="submit"]');

        // pending: the code, the waiting line and the check button, which a script would hide
        self::assertTrue(self::displayed('[data-pairing-poll]'), 'the code is shown');
        self::assertSame('Čekám na potvrzení v KissJ…', self::text('[data-pairing-status]'));
        self::assertTrue(self::displayed('.pairing-check button'), 'the check button is offered');
        self::assertSame('Zkontrolovat přihlášení', self::text('.pairing-check button'));

        self::pairWithoutScript(self::SLUG);

        // the fixture participant has programmes: Moje
        self::assertSame('/' . self::SLUG . '/programy/moje', self::path(), 'the check lands on Moje');
        self::assertSame('Jsi přihlášen', self::text('[data-toast] strong'), 'the login toast, on the landing');
        self::assertTrue(self::displayed('.tab-avatar'), 'the Profil tab names who is paired');
        self::assertSame('JN', self::text('.tab-avatar'));
    }

    public function testAReaderWithoutProgrammesLandsOnPrehled(): void
    {
        self::writeStubRegistrations(self::SLUG, ['1' => []]);

        self::pairWithoutScript(self::SLUG);

        self::assertSame('/' . self::SLUG . '/programy/prehled', self::path());
    }

    public function testALoginFromASheetComesBackToIt(): void
    {
        self::visit('/' . self::SLUG . '/programy/prehled');
        self::follow('#pg-view-overview a.pl-open[data-pg-open="1"]');

        self::follow('.sheet-body:target .sheet-footer a');

        self::assertSame('/' . self::SLUG . '/profil?program=1', self::path(), "the sheet's login link names its programme");
        self::follow('form[action="/' . self::SLUG . '/profil/propojit"] button[type="submit"]');
        self::follow('form[action="/' . self::SLUG . '/profil/kontrola"] button[type="submit"]');

        self::assertSame('/' . self::SLUG . '/programy#section-1-program-1', self::path(), 'the check lands on the sheet, on Harmonogram');
        self::assertTrue(self::displayed('.sheet-body:target'), 'the sheet is open');
        self::assertSame('1', self::attribute('.sheet-body:target', 'data-pg-detail'));
        self::assertTrue(self::displayed('[data-toast]'), 'the login toast');
        self::assertSame('Jsi přihlášen', self::text('[data-toast] strong'));
    }

    public function testLogoutSubmitsOnTheFirstTap(): void
    {
        self::pairWithoutScript(self::SLUG);
        self::follow('.tabbar a[href="/' . self::SLUG . '/profil"]');
        self::assertSame('Opravdu odhlásit?', self::attribute('.profile-logout button', 'data-confirm'));

        // one tap: without a script nothing asks first, the form posts
        self::follow('.profile-logout button');

        self::assertSame('/' . self::SLUG . '/profil', self::path());
        self::assertFalse(self::present('.profile-who'), 'nobody is paired');
        self::assertSame('Nejsi přihlášen', self::text('.login-box h2'));
        self::assertTrue(self::displayed('[data-toast]'), 'the logout toast');
        self::assertStringContainsString('Odhlášeno', self::text('[data-toast]'));
    }
}
