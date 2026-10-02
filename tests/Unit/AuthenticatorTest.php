<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Authenticator;
use App\Auth\Identity;
use App\Session;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testLoginRoundtrip(): void
    {
        $auth = new Authenticator(new Session());

        self::assertFalse($auth->isLogged());
        self::assertNull($auth->identity());

        $auth->store(new Identity(displayName: 'TIE ABC123', tieCode: 'ABC123'));

        self::assertTrue($auth->isLogged());
        self::assertSame('TIE ABC123', $auth->identity()->displayName);
        self::assertSame('ABC123', $auth->identity()->tieCode);

        $auth->logout();
        self::assertFalse($auth->isLogged());
    }

    public function testAStoredSkautisIdentityFromBeforeTheRemovalCountsAsLoggedOut(): void
    {
        // a session written by the old code must not crash the app bar
        $_SESSION = ['identity' => ['type' => 'skautis', 'displayName' => 'Jan Novák (jnovak)', 'skautisUserId' => 123, 'tieCode' => null]];
        $auth = new Authenticator(new Session());

        self::assertNull($auth->identity());
        self::assertFalse($auth->isLogged());
    }
}
