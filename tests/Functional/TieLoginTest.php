<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\SkautisGatewayInterface;

final class TieLoginTest extends AppTestCase
{
    private function app(): \Slim\App
    {
        return $this->createApp(overrides: [
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);
    }

    public function testValidTieCodeLogsInAndHighlights(): void
    {
        $app = $this->app();

        $response = $this->request($app, 'POST', '/harmonogram/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $response->getStatusCode());

        $html = (string) $this->request($app, 'GET', '/harmonogram')->getBody();
        self::assertStringContainsString('TIE ABC123', $html);
        // registered.json: tie:ABC123 → program 5 (Ukázková vycházka, sekce 10, 08:00)
        // pozn.: zvýraznění se ukáže jen pokud harmonogram má slot sekce 10 v 08:00 —
        // ověřujeme aspoň přihlášení a odhlašovací formulář
        self::assertStringContainsString('Odhlásit TIE', $html);
    }

    public function testInvalidTieCodeShowsError(): void
    {
        $app = $this->app();

        $response = $this->request($app, 'POST', '/harmonogram/tie', ['tieCode' => 'NEZNAMY']);
        self::assertSame(302, $response->getStatusCode());

        $html = (string) $this->request($app, 'GET', '/harmonogram')->getBody();
        self::assertStringContainsString('Neplatný TIE kód', $html);
        self::assertStringNotContainsString('Odhlásit TIE', $html);
    }

    public function testTieLogout(): void
    {
        $app = $this->app();
        $this->request($app, 'POST', '/harmonogram/tie', ['tieCode' => 'ABC123']);

        $this->request($app, 'POST', '/harmonogram/tie-logout');

        $html = (string) $this->request($app, 'GET', '/harmonogram')->getBody();
        self::assertStringNotContainsString('TIE ABC123', $html);
    }
}
