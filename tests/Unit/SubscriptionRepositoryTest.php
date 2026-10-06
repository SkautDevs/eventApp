<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\SubscriptionRepository;
use App\Storage\Database;
use App\Storage\Migrator;
use PHPUnit\Framework\TestCase;

final class SubscriptionRepositoryTest extends TestCase
{
    private function repo(): SubscriptionRepository
    {
        $pdo = Database::open(':memory:');
        (new Migrator())->migrate($pdo);

        return new SubscriptionRepository($pdo);
    }

    public function testSaveIsIdempotentPerEndpoint(): void
    {
        $repo = $this->repo();
        $repo->save(['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK1', 'auth' => 'AT1']], 'obrok19');
        $repo->save(['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK2', 'auth' => 'AT2']], 'obrok19');

        self::assertSame(1, $repo->count());
        self::assertSame('PK2', $repo->forEvent('obrok19')[0]['publicKey']);
    }

    public function testFindLooksUpOneEndpointWithinItsEvent(): void
    {
        $repo = $this->repo();
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
        $repo = $this->repo();
        $repo->save(['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']], 'obrok19');

        $repo->delete('obrok19', 'https://push.example/abc');

        self::assertSame(0, $repo->count());
    }

    public function testDeleteIsScopedToItsEvent(): void
    {
        $repo = $this->repo();
        $sub = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']];
        $repo->save($sub, 'obrok19');
        $repo->save($sub, 'korbo26');

        $repo->delete('korbo26', 'https://push.example/abc');

        self::assertSame(1, $repo->count('obrok19'), 'one event cleaning up must not remove another event\'s subscriber');
        self::assertSame(0, $repo->count('korbo26'));
    }

    public function testTheSameEndpointMayBelongToTwoEvents(): void
    {
        $repo = $this->repo();
        $repo->save(['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'k1', 'auth' => 'a1']], 'korbo26', 'KORBO1');
        $repo->save(['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'k2', 'auth' => 'a2']], 'obrok27');

        self::assertSame(2, $repo->count());
        self::assertSame('KORBO1', $repo->find('korbo26', 'https://push.example/a')['tieCode']);
        self::assertSame('k2', $repo->find('obrok27', 'https://push.example/a')['publicKey']);
        self::assertSame('k1', $repo->find('korbo26', 'https://push.example/a')['publicKey'], 'saving in one event replaces nothing in another');
    }

    public function testInvalidShapeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo()->save(['endpoint' => 'https://push.example/abc'], 'obrok19');
    }

    public function testSubscriptionsAreKeptPerEvent(): void
    {
        $repo = $this->repo();
        $repo->save(['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'korbo26');
        $repo->save(['endpoint' => 'https://push.example/b', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'obrok27');

        self::assertSame(['https://push.example/a'], array_column($repo->forEvent('korbo26'), 'endpoint'));
        self::assertSame(1, $repo->count('obrok27'));
    }

    public function testATieCodeIsStoredAndReplacedWithTheEndpoint(): void
    {
        $repo = $this->repo();
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
        $repo = $this->repo();
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

    public function testTheConstructorRunsNoDdl(): void
    {
        $pdo = Database::open(':memory:');

        new SubscriptionRepository($pdo);
        new \App\Push\MessageRepository($pdo);

        self::assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll());
    }

    public function testNoteFailuresDeletesRowsThatReachTheLimitWithinTheirEventOnly(): void
    {
        $repo = $this->repo();
        foreach (['a', 'b'] as $name) {
            $repo->save(['endpoint' => 'https://push.example/' . $name, 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']], 'korbo26');
        }
        $repo->save(['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']], 'obrok27');

        self::assertSame(0, $repo->noteFailures('korbo26', ['https://push.example/a', 'https://push.example/b'], 2));
        self::assertSame(0, $repo->noteFailures('korbo26', [], 2));
        self::assertSame(1, $repo->noteFailures('korbo26', ['https://push.example/a', 'https://push.example/a'], 2), 'a duplicate counts once');

        self::assertNull($repo->find('korbo26', 'https://push.example/a'));
        self::assertNotNull($repo->find('korbo26', 'https://push.example/b'));
        self::assertNotNull($repo->find('obrok27', 'https://push.example/a'), 'another event\'s row keeps its own count');
    }

    public function testClearFailuresStartsTheCountAgain(): void
    {
        $repo = $this->repo();
        $repo->save(['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']], 'korbo26');

        $repo->noteFailures('korbo26', ['https://push.example/a'], 2);
        $repo->clearFailures('korbo26', ['https://push.example/a']);
        $repo->clearFailures('korbo26', []);

        self::assertSame(0, $repo->noteFailures('korbo26', ['https://push.example/a'], 2));
        self::assertSame(1, $repo->count('korbo26'));
    }

    /** Review T3-I1: re-posting the same body must not wipe the strikes; new keys are a new subscription. */
    public function testAReSubscribeKeepsTheStrikesUnlessTheKeysChange(): void
    {
        $pdo = Database::open(':memory:');
        (new Migrator())->migrate($pdo);
        $repo = new SubscriptionRepository($pdo);
        $failures = static fn (): int => (int) $pdo->query("SELECT failures FROM subscriptions WHERE endpoint = 'https://push.example/a'")->fetchColumn();
        $sub = ['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']];
        $repo->save($sub, 'korbo26');
        for ($i = 0; $i < 3; $i++) {
            $repo->noteFailures('korbo26', ['https://push.example/a'], 5);
        }

        $repo->save($sub, 'korbo26', 'korbo1');
        self::assertSame(3, $failures(), 'same keys: the count stays');
        self::assertSame('KORBO1', $repo->find('korbo26', 'https://push.example/a')['tieCode'], 'the rest of the row is updated');

        $repo->save(['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'PK2', 'auth' => 'AT']], 'korbo26');
        self::assertSame(0, $failures(), 'new keys: the count starts again');
        self::assertSame(1, $repo->count('korbo26'));
        self::assertNull($repo->find('korbo26', 'https://push.example/a')['tieCode'], 'a logged-out re-send clears the code, as before');
    }
}
