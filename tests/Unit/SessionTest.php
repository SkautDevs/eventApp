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

    public function testNamespacesDoNotSeeEachOther(): void
    {
        $_SESSION = [];
        $a = new Session('korbo26');
        $b = new Session('obrok27');
        $a->set('identity', ['type' => 'tie']);

        self::assertTrue($a->has('identity'));
        self::assertFalse($b->has('identity'));
        self::assertSame(['korbo26' => ['identity' => ['type' => 'tie']]], $_SESSION);
    }

    public function testDeleteRemovesAStoredNull(): void
    {
        $session = new Session();
        $session->set('klic', null);
        $session->delete('klic');

        self::assertSame([], $_SESSION);
    }

    public function testANamespacedDeleteLeavesOtherEventsAlone(): void
    {
        $a = new Session('korbo26');
        $b = new Session('obrok27');
        $a->set('identity', 'a');
        $b->set('identity', 'b');

        $a->delete('identity');

        self::assertFalse($a->has('identity'));
        self::assertSame('b', $b->get('identity'));
    }

    public function testALegacyTopLevelIdentityIsIgnoredByANamespacedSession(): void
    {
        $_SESSION = ['identity' => ['type' => 'tie']];
        $session = new Session('korbo26');

        self::assertFalse($session->has('identity'));
        self::assertNull($session->get('identity'));
        $session->delete('identity');
        self::assertSame(['identity' => ['type' => 'tie']], $_SESSION);
    }
}
