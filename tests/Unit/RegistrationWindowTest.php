<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Program\RegistrationWindow;
use PHPUnit\Framework\TestCase;

final class RegistrationWindowTest extends TestCase
{
    private static function window(): RegistrationWindow
    {
        return new RegistrationWindow(
            new \DateTimeImmutable('2027-03-15T18:00:00+01:00'),
            new \DateTimeImmutable('2027-05-19T00:00:00+02:00'),
        );
    }

    public function testBeforeTheStartItIsBefore(): void
    {
        $now = new \DateTimeImmutable('2027-03-15T17:59:59+01:00');

        self::assertSame('before', self::window()->state($now));
        self::assertFalse(self::window()->isOpen($now));
    }

    public function testTheStartItselfIsOpen(): void
    {
        $now = new \DateTimeImmutable('2027-03-15T18:00:00+01:00');

        self::assertSame('open', self::window()->state($now));
        self::assertTrue(self::window()->isOpen($now));
    }

    public function testBetweenStartAndEndItIsOpenInAnyZone(): void
    {
        self::assertSame('open', self::window()->state(new \DateTimeImmutable('2027-05-18T21:59:59+00:00')));
    }

    public function testTheEndItselfIsAfter(): void
    {
        // the same instant written in UTC
        $now = new \DateTimeImmutable('2027-05-18T22:00:00+00:00');

        self::assertSame('after', self::window()->state($now));
        self::assertFalse(self::window()->isOpen($now));
    }

    public function testEitherEndMissingIsNone(): void
    {
        $now = new \DateTimeImmutable('2027-04-01T12:00:00+02:00');

        self::assertSame('none', (new RegistrationWindow(null, null))->state($now));
        self::assertSame('none', (new RegistrationWindow(new \DateTimeImmutable('2027-03-15T18:00:00+01:00'), null))->state($now));
        self::assertSame('none', (new RegistrationWindow(null, new \DateTimeImmutable('2027-05-19T00:00:00+02:00')))->state($now));
        self::assertFalse((new RegistrationWindow(null, null))->isOpen($now));
    }
}
