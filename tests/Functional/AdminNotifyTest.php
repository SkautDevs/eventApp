<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Push\PushSenderInterface;

final class SpyPushSender implements PushSenderInterface
{
    public array $calls = [];

    public function sendToAll(string $title, string $body, ?string $icon = null): array
    {
        $this->calls[] = [$title, $body, $icon];

        return ['sent' => 2, 'removed' => 1];
    }
}

final class AdminNotifyTest extends AppTestCase
{
    private SpyPushSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_TOKEN'] = 'tajny-token';
        $this->sender = new SpyPushSender();
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_TOKEN']);
    }

    private function app(): \Slim\App
    {
        return $this->createApp(overrides: [PushSenderInterface::class => $this->sender]);
    }

    public function testFormRendersWithValidToken(): void
    {
        $response = $this->request($this->app(), 'GET', '/admin/notify?token=tajny-token');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Odeslat notifikaci', $html);
        // the token is carried into the form by a hidden field
        self::assertStringContainsString('name="token" value="tajny-token"', $html);
    }

    public function testFormWithoutTokenIs403(): void
    {
        self::assertSame(403, $this->request($this->app(), 'GET', '/admin/notify')->getStatusCode());
    }

    public function testWrongTokenPostIs403AndNothingSent(): void
    {
        $response = $this->request($this->app(), 'POST', '/admin/notify', [
            'token' => 'spatny', 'title' => 'Test', 'body' => 'Zprava',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->sender->calls);
    }

    public function testMissingAdminTokenConfigIs403(): void
    {
        $_ENV['ADMIN_TOKEN'] = '';

        $response = $this->request($this->app(), 'POST', '/admin/notify', [
            'token' => '', 'title' => 'Test', 'body' => 'Zprava',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCorrectTokenSends(): void
    {
        $response = $this->request($this->app(), 'POST', '/admin/notify', [
            'token' => 'tajny-token', 'title' => 'Zmena programu', 'body' => 'Koncert na stagi!',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->sender->calls);
        self::assertSame('Zmena programu', $this->sender->calls[0][0]);
        self::assertStringContainsString('Odesláno: 2', (string) $response->getBody());
    }
}
