<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Push\SubscriptionRepository;

final class PushUnsubscribeTest extends AppTestCase
{
    private const SUB = ['endpoint' => 'https://push.example/xyz', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']];

    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = self::memoryDb();
    }

    private function repo(): SubscriptionRepository
    {
        return new SubscriptionRepository($this->pdo);
    }

    public function testASavedRowIsDeletedWith204(): void
    {
        $this->repo()->save(self::SUB, 'obrok19');
        $app = $this->createApp(overrides: [\PDO::class => $this->pdo]);

        $response = $this->request($app, 'POST', '/push/unsubscribe', ['endpoint' => self::SUB['endpoint']]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(0, $this->repo()->count('obrok19'));
    }

    /** Unauthenticated: the answer must not tell anyone which endpoints exist. */
    public function testAnUnknownEndpointIs204AndChangesNothing(): void
    {
        $this->repo()->save(self::SUB, 'obrok19');
        $app = $this->createApp(overrides: [\PDO::class => $this->pdo]);

        $response = $this->request($app, 'POST', '/push/unsubscribe', ['endpoint' => 'https://push.example/someone-else']);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(1, $this->repo()->count('obrok19'));
    }

    public function testAnotherEventsRowIsUntouched(): void
    {
        $this->repo()->save(self::SUB, 'obrok19');
        $this->repo()->save(self::SUB, 'korbo26');
        $app = $this->createApp('korbo26', overrides: [\PDO::class => $this->pdo]);

        $this->request($app, 'POST', '/push/unsubscribe', ['endpoint' => self::SUB['endpoint']]);

        self::assertSame(0, $this->repo()->count('korbo26'));
        self::assertSame(1, $this->repo()->count('obrok19'));
    }

    public function testABodyWithoutAStringEndpointIs400(): void
    {
        $this->repo()->save(self::SUB, 'obrok19');
        $app = $this->createApp(overrides: [\PDO::class => $this->pdo]);

        foreach (['no body' => null, 'empty' => [], 'a number' => ['endpoint' => 42], 'a list' => ['endpoint' => [self::SUB['endpoint']]]] as $case => $body) {
            self::assertSame(400, $this->request($app, 'POST', '/push/unsubscribe', $body)->getStatusCode(), $case);
        }
        self::assertSame(1, $this->repo()->count('obrok19'));
    }
}
