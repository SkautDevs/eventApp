<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Module\Profile\PairingCheck;
use App\Program\Participant;
use App\Program\Programme;
use PHPUnit\Framework\TestCase;

/** Where an approved login lands (PairingCheck::landing()): the first rule that matches wins. */
final class PairingLandingTest extends TestCase
{
    public function testAReturnTargetWins(): void
    {
        self::assertSame('programy#section-1-program-1', PairingCheck::landing('programy#section-1-program-1', self::withProgrammes(), true));
    }

    public function testAParticipantWithProgrammesLandsOnMoje(): void
    {
        self::assertSame('programy/moje', PairingCheck::landing(null, self::withProgrammes(), true));
    }

    public function testAParticipantWithoutProgrammesLandsOnPrehled(): void
    {
        self::assertSame('programy/prehled', PairingCheck::landing(null, new Participant(1, null, []), true));
    }

    public function testAnEventWithoutProgramLandsOnProfil(): void
    {
        self::assertSame('profil', PairingCheck::landing('programy#section-1-program-1', self::withProgrammes(), false));
        self::assertSame('profil', PairingCheck::landing(null, new Participant(1, null, []), false));
    }

    private static function withProgrammes(): Participant
    {
        $programme = Programme::fromArray(['id' => 5, 'name' => 'X', 'section' => ['id' => 2]]);
        self::assertNotNull($programme);

        return new Participant(1, null, [$programme]);
    }
}
