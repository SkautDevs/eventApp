<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Environment;
use App\EventConfig;
use App\Module\Admin\AdminSession;
use App\Session;
use PHPUnit\Framework\TestCase;
use Tests\FixedClock;

/** The admin page's login, CSRF token, one-time nonces and flashed result, in the event's session values. */
final class AdminSessionTest extends TestCase
{
    private FixedClock $clock;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->clock = new FixedClock(new \DateTimeImmutable('2027-05-20 10:00:00'));
        $_ENV['ADMIN_TOKEN_OBROK27'] = 'tajny-token';
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_TOKEN_OBROK27']);
        $_SESSION = [];
    }

    private function admin(): AdminSession
    {
        return new AdminSession(
            new Session('obrok27'),
            Environment::fromGlobals(),
            EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok27'),
            $this->clock,
        );
    }

    public function testTheRightTokenOpensTheSessionForADay(): void
    {
        $admin = $this->admin();
        self::assertFalse($admin->isOpen());
        self::assertTrue($admin->open('tajny-token'));
        self::assertTrue($admin->isOpen());
        self::assertSame(['since' => $this->clock->now()->getTimestamp()], $_SESSION['obrok27']['admin']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $admin->csrf());

        $this->clock->set(new \DateTimeImmutable('2027-05-21 09:59:59'));
        self::assertTrue($admin->isOpen());
        $this->clock->set(new \DateTimeImmutable('2027-05-21 10:00:00'));
        self::assertFalse($admin->isOpen());
    }

    public function testAWrongTokenOpensNothing(): void
    {
        $admin = $this->admin();
        self::assertFalse($admin->open('wrong'));
        self::assertFalse($admin->isOpen());
        self::assertArrayNotHasKey('obrok27', $_SESSION);
    }

    public function testNoConfiguredTokenOpensNothingAndClosesAnOpenSession(): void
    {
        $this->admin()->open('tajny-token');
        $_ENV['ADMIN_TOKEN_OBROK27'] = '';
        $admin = $this->admin();

        self::assertFalse($admin->open(''));
        self::assertFalse($admin->isOpen());
    }

    public function testReopeningKeepsAWellFormedCsrfToken(): void
    {
        $admin = $this->admin();
        $admin->open('tajny-token');
        $csrf = $admin->csrf();
        $admin->open('tajny-token');

        self::assertSame($csrf, $admin->csrf());
        self::assertTrue($admin->csrfMatches($csrf));
        self::assertFalse($admin->csrfMatches('nope'));
        self::assertFalse($admin->csrfMatches(null));
    }

    public function testANonceIsConsumedOnceAndOnlyFiftyStayValid(): void
    {
        $admin = $this->admin();
        $first = $admin->issueNonce();
        $nonce = $admin->issueNonce();

        self::assertTrue($admin->consumeNonce($nonce));
        self::assertFalse($admin->consumeNonce($nonce));
        self::assertFalse($admin->consumeNonce('never-issued'));

        for ($i = 0; $i < 50; $i++) {
            $admin->issueNonce();
        }
        self::assertFalse($admin->consumeNonce($first));
        self::assertCount(50, $_SESSION['obrok27']['notifyNonces']);
    }

    public function testAResultIsTakenOnce(): void
    {
        $admin = $this->admin();
        self::assertNull($admin->takeResult());

        $admin->flashResult(['sent' => 1, 'removed' => 0, 'failed' => 0, 'unreached' => null], 'Lung');
        self::assertSame(
            ['result' => ['sent' => 1, 'removed' => 0, 'failed' => 0, 'unreached' => null], 'signature' => 'Lung'],
            $admin->takeResult(),
        );
        self::assertNull($admin->takeResult());
    }
}
