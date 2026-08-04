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

        $response = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $response->getStatusCode());

        $profile = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringContainsString('TIE ABC123', $profile);
        self::assertStringContainsString('Odhlásit TIE', $profile);

        // registered.json: tie:ABC123 → program 5 (Ukázková vycházka), which the
        // programme screen then marks as theirs
        $programs = (string) $this->request($app, 'GET', '/programy')->getBody();
        self::assertStringContainsString('TIE ABC123', $programs);
        self::assertStringContainsString('is-registered', $programs);
    }

    public function testTheAppBarShowsWhoIsLoggedIn(): void
    {
        $app = $this->app();
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);

        // the identity is a global, so it has to reach a screen that knows nothing about auth
        $html = (string) $this->request($app, 'GET', '/novinky')->getBody();
        self::assertStringContainsString('<span class="appbar-who">TIE ABC123</span>', $html);
    }

    public function testInvalidTieCodeShowsError(): void
    {
        $app = $this->app();

        $response = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'NEZNAMY']);
        self::assertSame(302, $response->getStatusCode());

        $html = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringContainsString('Neplatný TIE kód', $html);
        self::assertStringNotContainsString('Odhlásit TIE', $html);
    }

    public function testTieLogout(): void
    {
        $app = $this->app();
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);

        $this->request($app, 'POST', '/profil/tie-logout');

        $html = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringNotContainsString('TIE ABC123', $html);
    }
}
