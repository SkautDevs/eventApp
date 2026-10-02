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

    /** The fixture is the kissj list's own `sections`, so the stub maps it the same way. */
    public function testGetSectionsIsKeyedByIdInFixtureOrder(): void
    {
        $sections = $this->provider->getSections();

        self::assertSame([10, 12, 13, 1, 2, 11, 14, 15, 16, 3, 4, 17, 18], array_keys($sections));
        self::assertSame(['id' => 10, 'title' => 'Putování', 'subTitle' => null, 'image' => null, 'attachment' => null], $sections[10]);
        // relative paths, as www/ serves them today
        self::assertSame('1. blok', $sections[3]['subTitle']);
        self::assertSame('events/obrok19/map-vzlet.png', $sections[2]['image']);
        self::assertSame(
            ['href' => 'events/obrok19/netradicni-sporty.pdf', 'label' => 'Pravidla a více informací zde'],
            $sections[17]['attachment'],
        );
    }

    public function testNoSectionsFixtureMeansNoSections(): void
    {
        $provider = new StubProgramProvider(sys_get_temp_dir() . '/no-such-fixtures-' . bin2hex(random_bytes(4)));

        self::assertSame([], $provider->getSections());
    }

    public function testUnknownTieCodeThrows(): void
    {
        $identity = new Identity(displayName: 'TIE NEZNAMY', tieCode: 'NEZNAMY');

        $this->expectException(UnknownParticipantException::class);
        $this->provider->getProgramsForIdentity($identity);
    }

    public function testTieCodesForAProgrammeAreRegisteredJsonInverted(): void
    {
        $dir = sys_get_temp_dir() . '/stub-tie-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/registered.json', json_encode(['tie:KORBO1' => [3, 5], 'tie:KORBO2' => [5], 'tie:KORBO3' => []]));
        $provider = new StubProgramProvider($dir);

        self::assertSame(['KORBO1', 'KORBO2'], $provider->getTieCodesForProgramme(5));
        self::assertSame(['KORBO1'], $provider->getTieCodesForProgramme(3));
        self::assertSame([], $provider->getTieCodesForProgramme(99));

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
