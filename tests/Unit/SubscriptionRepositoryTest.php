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

        $repo->save($sub);
        $repo->save(['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK2', 'auth' => 'AT2']]);

        self::assertSame(1, $repo->count());
        self::assertSame('PK2', $repo->all()[0]['publicKey']);
    }

    public function testDelete(): void
    {
        $repo = new SubscriptionRepository($this->dbPath);
        $repo->save(['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']]);

        $repo->delete('https://push.example/abc');

        self::assertSame(0, $repo->count());
    }

    public function testInvalidShapeThrows(): void
    {
        $repo = new SubscriptionRepository($this->dbPath);

        $this->expectException(\InvalidArgumentException::class);
        $repo->save(['endpoint' => 'https://push.example/abc']);
    }
}
