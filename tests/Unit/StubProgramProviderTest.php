<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;
use App\Program\StubProgramProvider;
use PHPUnit\Framework\TestCase;

final class StubProgramProviderTest extends TestCase
{
    private StubProgramProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new StubProgramProvider(dirname(__DIR__, 2) . '/events/obrok19/fixtures');
    }

    public function testGetPrograms(): void
    {
        $programs = $this->provider->getPrograms();

        self::assertNotEmpty($programs);
        self::assertArrayHasKey('name', $programs[0]);
        self::assertArrayHasKey('id', $programs[0]['section']);
    }

    public function testRegisteredProgramsForSkautisUser(): void
    {
        $identity = new Identity(type: 'skautis', displayName: 'Test User', skautisUserId: 123);

        $programs = $this->provider->getProgramsForIdentity($identity);

        self::assertCount(2, $programs);
    }

    public function testUnknownSkautisUserReturnsEmpty(): void
    {
        $identity = new Identity(type: 'skautis', displayName: 'Nikdo', skautisUserId: 999999);

        self::assertSame([], $this->provider->getProgramsForIdentity($identity));
    }

    public function testUnknownTieCodeThrows(): void
    {
        $identity = new Identity(type: 'tie', displayName: 'TIE NEZNAMY', tieCode: 'NEZNAMY');

        $this->expectException(UnknownParticipantException::class);
        $this->provider->getProgramsForIdentity($identity);
    }
}
