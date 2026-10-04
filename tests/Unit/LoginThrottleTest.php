<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\LoginThrottle;
use App\Storage\Database;
use App\Storage\Migrator;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::open(':memory:');
        (new Migrator())->migrate($this->pdo);
    }

    private function failTimes(LoginThrottle $throttle, int $times, string $event = 'korbo26', string $ip = '10.0.0.1', ?\DateTimeImmutable $at = null): void
    {
        for ($i = 0; $i < $times; $i++) {
            $throttle->recordFailure($event, $ip, $at);
        }
    }

    public function testFiftyNineFailuresAllowAndSixtyRefuse(): void
    {
        $throttle = new LoginThrottle($this->pdo);

        $this->failTimes($throttle, 59);
        self::assertFalse($throttle->tooMany('korbo26', '10.0.0.1'));

        $this->failTimes($throttle, 1);
        self::assertTrue($throttle->tooMany('korbo26', '10.0.0.1'));
    }

    public function testFailuresOlderThanTenMinutesDoNotCount(): void
    {
        $throttle = new LoginThrottle($this->pdo);
        $now = new \DateTimeImmutable('2026-10-04 18:00:00', new \DateTimeZone('Europe/Prague'));

        $this->failTimes($throttle, 60, at: $now->modify('-11 minutes'));

        self::assertFalse($throttle->tooMany('korbo26', '10.0.0.1', $now));
        self::assertTrue($throttle->tooMany('korbo26', '10.0.0.1', $now->modify('-5 minutes')));
    }

    public function testEachEventAndEachAddressHasItsOwnCount(): void
    {
        $throttle = new LoginThrottle($this->pdo);

        $this->failTimes($throttle, 60, 'korbo26', '10.0.0.1');

        self::assertTrue($throttle->tooMany('korbo26', '10.0.0.1'));
        self::assertFalse($throttle->tooMany('obrok27', '10.0.0.1'));
        self::assertFalse($throttle->tooMany('korbo26', '10.0.0.2'));
    }
}
