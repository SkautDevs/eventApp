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

        $auth->store(new Identity(type: 'skautis', displayName: 'Jan Novák (jnovak)', skautisUserId: 123));

        self::assertTrue($auth->isLogged());
        self::assertSame('Jan Novák (jnovak)', $auth->identity()->displayName);
        self::assertSame(123, $auth->identity()->skautisUserId);

        $auth->logout();
        self::assertFalse($auth->isLogged());
    }
}
