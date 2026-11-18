<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\PairingThrottle;
use App\Storage\Database;
use App\Storage\Migrator;
use PHPUnit\Framework\TestCase;
use Tests\FixedClock;

final class PairingThrottleTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::open(':memory:');
        (new Migrator())->migrate($this->pdo);
    }

    private function startTimes(PairingThrottle $throttle, int $times, string $event = 'korbo26', string $ip = '10.0.0.1'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $throttle->record($event, $ip);
        }
    }

    /** Room for a camp behind one NAT: 299 starts in ten minutes pass, the 300th is the last. */
    public function testTwoHundredNinetyNineStartsAllowAndThreeHundredRefuse(): void
    {
        $throttle = new PairingThrottle($this->pdo);

        $this->startTimes($throttle, 299);
        self::assertFalse($throttle->tooMany('korbo26', '10.0.0.1'));

        $this->startTimes($throttle, 1);
        self::assertTrue($throttle->tooMany('korbo26', '10.0.0.1'));
    }

    public function testStartsOlderThanTenMinutesDoNotCount(): void
    {
        $now = new \DateTimeImmutable('2026-10-04 18:00:00', new \DateTimeZone('Europe/Prague'));
        $clock = new FixedClock($now->modify('-11 minutes'));
        $throttle = new PairingThrottle($this->pdo, $clock);

        $this->startTimes($throttle, 300);

        $clock->set($now);
        self::assertFalse($throttle->tooMany('korbo26', '10.0.0.1'));
        $clock->set($now->modify('-5 minutes'));
        self::assertTrue($throttle->tooMany('korbo26', '10.0.0.1'));
    }

    public function testEachEventAndEachAddressHasItsOwnCount(): void
    {
        $throttle = new PairingThrottle($this->pdo);

        $this->startTimes($throttle, 300, 'korbo26', '10.0.0.1');

        self::assertTrue($throttle->tooMany('korbo26', '10.0.0.1'));
        self::assertFalse($throttle->tooMany('obrok27', '10.0.0.1'));
        self::assertFalse($throttle->tooMany('korbo26', '10.0.0.2'));
    }

    public function testAStartIsARowOfPairingAttempts(): void
    {
        (new PairingThrottle($this->pdo))->record('korbo26', '10.0.0.1');

        self::assertSame(
            [['event' => 'korbo26', 'ip' => '10.0.0.1']],
            $this->pdo->query('SELECT event, ip FROM pairing_attempts')->fetchAll(\PDO::FETCH_ASSOC),
        );
    }
}
