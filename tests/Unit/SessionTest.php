<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testSetGetDelete(): void
    {
        $session = new Session();

        self::assertFalse($session->has('klic'));
        self::assertSame('default', $session->get('klic', 'default'));

        $session->set('klic', ['a' => 1]);
        self::assertTrue($session->has('klic'));
        self::assertSame(['a' => 1], $session->get('klic'));

        $session->delete('klic');
        self::assertFalse($session->has('klic'));
    }
}
