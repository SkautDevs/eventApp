<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\SubscriptionRepository;
use PHPUnit\Framework\TestCase;

final class SubscriptionRepositoryTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/push-test-' . uniqid() . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
    }

    public function testSaveIsIdempotentPerEndpoint(): void
    {
        $repo = new SubscriptionRepository($this->dbPath);
        $sub = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK1', 'auth' => 'AT1']];

        $repo->save($sub, 'obrok19');
        $repo->save(['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK2', 'auth' => 'AT2']], 'obrok19');

        self::assertSame(1, $repo->count());
        self::assertSame('PK2', $repo->forEvent('obrok19')[0]['publicKey']);
    }

    public function testFindLooksUpOneEndpointWithinItsEvent(): void
    {
        $repo = new SubscriptionRepository($this->dbPath);
        $repo->save(['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']], 'obrok19', 'abc123');

        self::assertSame(
            ['endpoint' => 'https://push.example/abc', 'publicKey' => 'PK', 'authToken' => 'AT', 'tieCode' => 'ABC123'],
            $repo->find('obrok19', 'https://push.example/abc'),
        );
        self::assertNull($repo->find('obrok27', 'https://push.example/abc'));
        self::assertNull($repo->find('obrok19', 'https://push.example/other'));
    }

    public function testDelete(): void
    {
        $repo = new SubscriptionRepository($this->dbPath);
        $repo->save(['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']], 'obrok19');

        $repo->delete('https://push.example/abc');

        self::assertSame(0, $repo->count());
    }

    public function testInvalidShapeThrows(): void
    {
        $repo = new SubscriptionRepository($this->dbPath);

        $this->expectException(\InvalidArgumentException::class);
        $repo->save(['endpoint' => 'https://push.example/abc'], 'obrok19');
    }

    public function testSubscriptionsAreKeptPerEvent(): void
    {
        $repo = new SubscriptionRepository(':memory:');
        $repo->save(['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'korbo26');
        $repo->save(['endpoint' => 'https://push.example/b', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'obrok27');

        self::assertSame(['https://push.example/a'], array_column($repo->forEvent('korbo26'), 'endpoint'));
        self::assertSame(1, $repo->count('obrok27'));
    }

    public function testResubscribingAnEndpointMovesItToTheNewEvent(): void
    {
        $repo = new SubscriptionRepository(':memory:');
        $sub = ['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'k', 'auth' => 'a']];
        $repo->save($sub, 'korbo26');
        $repo->save($sub, 'obrok27');

        self::assertSame(1, $repo->count());
        self::assertSame([], $repo->forEvent('korbo26'));
        self::assertSame(1, $repo->count('obrok27'));
    }

    public function testAPreEventDatabaseIsMigratedAndItsRowsGoNowhere(): void
    {
        $path = sys_get_temp_dir() . '/push-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->exec('CREATE TABLE subscriptions (endpoint TEXT PRIMARY KEY, public_key TEXT NOT NULL, auth_token TEXT NOT NULL, created_at TEXT NOT NULL)');
            $pdo->exec("INSERT INTO subscriptions VALUES ('https://push.example/old', 'k', 'a', '2019-01-01')");
            unset($pdo);

            $repo = new SubscriptionRepository($path);

            self::assertSame([], $repo->forEvent('obrok19'));
            self::assertSame(1, $repo->count());

            // a second boot on the already migrated file must neither throw nor lose rows
            $again = new SubscriptionRepository($path);
            self::assertSame(1, $again->count());
        } finally {
            @unlink($path);
        }
    }

    public function testATieCodeIsStoredAndReplacedWithTheEndpoint(): void
    {
        $repo = new SubscriptionRepository(':memory:');
        $sub = ['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'k', 'auth' => 'a']];

        $repo->save($sub, 'korbo26');
        self::assertNull($repo->forEvent('korbo26')[0]['tieCode']);

        $repo->save($sub, 'korbo26', 'korbo1');
        self::assertSame('KORBO1', $repo->forEvent('korbo26')[0]['tieCode']);

        $repo->save($sub, 'korbo26', null);
        self::assertNull($repo->forEvent('korbo26')[0]['tieCode']);
        self::assertSame(1, $repo->count('korbo26'));
    }

    public function testForEventFiltersByTieCodeCaseInsensitively(): void
    {
        $repo = new SubscriptionRepository(':memory:');
        $repo->save(['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'korbo26', 'KORBO1');
        $repo->save(['endpoint' => 'https://push.example/b', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'korbo26', 'KORBO2');
        $repo->save(['endpoint' => 'https://push.example/c', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'korbo26');
        $repo->save(['endpoint' => 'https://push.example/d', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'obrok27', 'KORBO1');

        self::assertSame(['https://push.example/a'], array_column($repo->forEvent('korbo26', ['korbo1']), 'endpoint'));
        self::assertSame([], $repo->forEvent('korbo26', []));
        self::assertCount(3, $repo->forEvent('korbo26'));
        $codes = $repo->subscribedTieCodes('korbo26');
        sort($codes);
        self::assertSame(['KORBO1', 'KORBO2'], $codes);
    }

    public function testAnOlderDatabaseGainsTheTieCodeColumn(): void
    {
        $path = sys_get_temp_dir() . '/push-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->exec("CREATE TABLE subscriptions (endpoint TEXT PRIMARY KEY, public_key TEXT NOT NULL, auth_token TEXT NOT NULL, created_at TEXT NOT NULL, event TEXT NOT NULL DEFAULT '')");
            $pdo->exec("INSERT INTO subscriptions VALUES ('https://push.example/old', 'k', 'a', '2026-01-01', 'korbo26')");
            unset($pdo);

            $repo = new SubscriptionRepository($path);

            self::assertNull($repo->forEvent('korbo26')[0]['tieCode']);
        } finally {
            @unlink($path);
        }
    }
}
