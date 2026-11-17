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

        $auth->store(new Identity(1, 'Jana Nováková', 'stub-1-0123456789abcdef'));

        self::assertTrue($auth->isLogged());
        self::assertSame(1, $auth->identity()->participantId);
        self::assertSame('Jana Nováková', $auth->identity()->displayName());
        self::assertSame('stub-1-0123456789abcdef', $auth->identity()->sessionToken);

        $auth->logout();
        self::assertFalse($auth->isLogged());
    }

    public function testAStoredSkautisIdentityFromBeforeTheRemovalCountsAsLoggedOut(): void
    {
        // a session written by the old code must not crash the app bar
        $_SESSION = ['identity' => ['type' => 'skautis', 'displayName' => 'Jan Novák (jnovak)', 'skautisUserId' => 123]];
        $auth = new Authenticator(new Session());

        self::assertNull($auth->identity());
        self::assertFalse($auth->isLogged());
    }

    public function testAnIdentityWithoutASessionTokenCountsAsLoggedOut(): void
    {
        // a code-only identity from before pairing carries no session token
        $_SESSION = ['identity' => ['displayName' => 'Účastník', 'participantId' => 1]];

        self::assertFalse((new Authenticator(new Session()))->isLogged());
    }
}
