<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\SubscribeThrottle;
use App\Storage\Database;
use App\Storage\Migrator;
use PHPUnit\Framework\TestCase;
use Tests\FixedClock;

final class SubscribeThrottleTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::open(':memory:');
        (new Migrator())->migrate($this->pdo);
    }

    private function recordTimes(SubscribeThrottle $throttle, int $times, string $event = 'korbo26', string $key = '10.0.0.1'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $throttle->record($event, $key);
        }
    }

    public function testTwentyNineSubscriptionsAllowAndThirtyRefuse(): void
    {
        $throttle = new SubscribeThrottle($this->pdo);

        $this->recordTimes($throttle, 299);
        self::assertFalse($throttle->tooMany('korbo26', '10.0.0.1'));

        $this->recordTimes($throttle, 1);
        self::assertTrue($throttle->tooMany('korbo26', '10.0.0.1'));
        self::assertSame(300, SubscribeThrottle::LIMIT);
    }

    public function testSubscriptionsOlderThanTenMinutesDoNotCount(): void
    {
        $now = new \DateTimeImmutable('2026-10-04 18:00:00', new \DateTimeZone('Europe/Prague'));
        $clock = new FixedClock($now->modify('-11 minutes'));
        $throttle = new SubscribeThrottle($this->pdo, $clock);

        $this->recordTimes($throttle, SubscribeThrottle::LIMIT);

        $clock->set($now);
        self::assertFalse($throttle->tooMany('korbo26', '10.0.0.1'));
        $clock->set($now->modify('-5 minutes'));
        self::assertTrue($throttle->tooMany('korbo26', '10.0.0.1'));
    }

    public function testEachEventAndEachAddressHasItsOwnCount(): void
    {
        $throttle = new SubscribeThrottle($this->pdo);

        $this->recordTimes($throttle, SubscribeThrottle::LIMIT, 'korbo26', '10.0.0.1');

        self::assertTrue($throttle->tooMany('korbo26', '10.0.0.1'));
        self::assertFalse($throttle->tooMany('obrok27', '10.0.0.1'));
        self::assertFalse($throttle->tooMany('korbo26', '2001:db8::/64'));
    }
}
