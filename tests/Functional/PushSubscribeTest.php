<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Push\SubscriptionRepository;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class PushSubscribeTest extends AppTestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/push-func-' . uniqid() . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
    }

    private function repo(): SubscriptionRepository
    {
        return new SubscriptionRepository($this->dbPath);
    }

    public function testSubscribeStoresSubscription(): void
    {
        $app = $this->createApp(overrides: [SubscriptionRepository::class => $this->repo()]);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/push/subscribe')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode([
                'endpoint' => 'https://push.example/xyz',
                'keys' => ['p256dh' => 'PK', 'auth' => 'AT'],
            ])));

        $response = $app->handle($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->repo()->count());
    }

    public function testInvalidBodyIs400(): void
    {
        $app = $this->createApp(overrides: [SubscriptionRepository::class => $this->repo()]);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/push/subscribe')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream('{"endpoint": "x"}'));

        self::assertSame(400, $app->handle($request)->getStatusCode());
        self::assertSame(0, $this->repo()->count());
    }
}
