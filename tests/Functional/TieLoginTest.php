<?php

declare(strict_types=1);

namespace Tests\Functional;

final class TieLoginTest extends AppTestCase
{
    private function app(): \Slim\App
    {
        return $this->createApp();
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

    public function testTheProfileOffersOnlyTheTieCode(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/profil')->getBody();

        self::assertStringNotContainsStringIgnoringCase('skautis', $html);
        self::assertStringContainsString('name="tieCode"', $html);
    }

    public function testThePostToTheRootIsGone(): void
    {
        self::assertSame(405, $this->request($this->createApp(), 'POST', '/', ['skautIS_Token' => 'abc'])->getStatusCode());
    }

    public function testLoggingIntoOneEventDoesNotLogIntoAnother(): void
    {
        $korbo = $this->createApp('korbo26');
        $this->request($korbo, 'POST', '/profil/tie', ['tieCode' => 'KORBO1']);

        $html = (string) $this->request($this->createApp('obrok19'), 'GET', '/profil')->getBody();
        self::assertStringNotContainsString('TIE KORBO1', $html);
    }

    public function testOneEventsTieCodeIsRejectedByAnother(): void
    {
        $app = $this->createApp('obrok19');
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'KORBO1']);

        $html = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringContainsString('Neplatný TIE kód.', $html);
        self::assertArrayNotHasKey('identity', $_SESSION['obrok19'] ?? []);
    }

    public function testLoggingOutOfOneEventKeepsTheOtherLoggedIn(): void
    {
        $korbo = $this->createApp('korbo26');
        $obrok = $this->createApp('obrok19');
        $this->request($korbo, 'POST', '/profil/tie', ['tieCode' => 'KORBO1']);
        $this->request($obrok, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);

        $this->request($obrok, 'POST', '/profil/tie-logout');

        self::assertStringContainsString('TIE KORBO1', (string) $this->request($korbo, 'GET', '/profil')->getBody());
        self::assertStringNotContainsString('TIE ABC123', (string) $this->request($obrok, 'GET', '/profil')->getBody());
    }
}
