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
        $a->set('identity', ['participantId' => 1]);

        self::assertTrue($a->has('identity'));
        self::assertFalse($b->has('identity'));
        self::assertSame(['korbo26' => ['identity' => ['participantId' => 1]]], $_SESSION);
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
        $_SESSION = ['identity' => ['participantId' => 1]];
        $session = new Session('korbo26');

        self::assertFalse($session->has('identity'));
        self::assertNull($session->get('identity'));
        $session->delete('identity');
        self::assertSame(['identity' => ['participantId' => 1]], $_SESSION);
    }

    public function testIdIsStableForOneSessionAndSharedByItsEvents(): void
    {
        $a = new Session('korbo26');
        $id = $a->id();

        self::assertNotSame('', $id);
        self::assertSame($id, $a->id());
        // one cookie per host: every event's device id comes from the same session
        self::assertSame($id, (new Session('obrok27'))->id());
        // and the events' own values do not see it
        self::assertNull($a->get('id'));
        self::assertFalse((new Session('obrok27'))->has('id'));
    }

    public function testANewSessionHasANewId(): void
    {
        $id = (new Session('korbo26'))->id();
        $_SESSION = [];

        self::assertNotSame($id, (new Session('korbo26'))->id());
    }
}
