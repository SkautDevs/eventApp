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

    private function counted(): CountingProgramProvider
    {
        return new CountingProgramProvider(new \App\Program\StubProgramProvider(dirname(__DIR__, 2) . '/events/obrok19/fixtures'));
    }

    public function testSixtyUnknownCodesFromOneAddressAreThrottled(): void
    {
        $provider = $this->counted();
        $app = $this->createApp(overrides: [\App\Program\ProgramProviderInterface::class => $provider]);
        for ($i = 1; $i <= 60; $i++) {
            $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'NEZNAMY' . $i]);
        }
        self::assertSame(60, $provider->identityCalls);

        $response = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(60, $provider->identityCalls, 'the 61st attempt must not reach the provider');
        $html = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringContainsString('Příliš mnoho pokusů, zkus to za chvíli.', $html);
        self::assertStringNotContainsString('Odhlásit TIE', $html);
    }

    public function testASuccessfulLoginDoesNotCountTowardsTheLimit(): void
    {
        $provider = $this->counted();
        $app = $this->createApp(overrides: [\App\Program\ProgramProviderInterface::class => $provider]);
        for ($i = 1; $i <= 59; $i++) {
            $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'NEZNAMY' . $i]);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        }
        // an empty code is not a guess either
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => '   ']);
        // logged in, /profil shows no form and so no error; logging out touches no counter
        $this->request($app, 'POST', '/profil/tie-logout');

        // the 60th failure still reaches the provider and is answered as a wrong code
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'NEZNAMY60']);
        self::assertSame(63, $provider->identityCalls);
        self::assertStringContainsString('Neplatný TIE kód.', (string) $this->request($app, 'GET', '/profil')->getBody());

        // and only now is the address over the limit
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        self::assertSame(63, $provider->identityCalls);
    }

    public function testTheProfileInvitesTheReaderInTheTyRegister(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/profil')->getBody();

        self::assertStringContainsString('Přihlas se a v programu se ti zvýrazní, na co máš registraci.', $html);
        self::assertStringNotContainsString('Přihlaste', $html);
    }

    public function testACrossSiteLoginIs403AndLogsNobodyIn(): void
    {
        $app = $this->createApp();
        $response = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123'], ['Sec-Fetch-Site' => 'cross-site']);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('TIE ABC123', (string) $this->request($app, 'GET', '/profil')->getBody());
    }

    public function testACrossSiteLogoutIs403AndKeepsTheReaderLoggedIn(): void
    {
        $app = $this->createApp();
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123'], ['Sec-Fetch-Site' => 'same-origin']);
        self::assertSame(403, $this->request($app, 'POST', '/profil/tie-logout', [], ['Origin' => 'https://evil.example'])->getStatusCode());
        self::assertStringContainsString('TIE ABC123', (string) $this->request($app, 'GET', '/profil')->getBody());
    }

    /** T2-b: the refusal is a page of the app that says what to do, not an empty body. */
    public function testACrossSiteLoginExplainsItselfInCzech(): void
    {
        $response = $this->request($this->createApp(), 'POST', '/profil/tie', ['tieCode' => 'ABC123'], ['Sec-Fetch-Site' => 'cross-site']);
        $html = (string) $response->getBody();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Přihlášení se nepodařilo. Načti stránku a zkus to znovu.', $html);
        self::assertMatchesRegularExpression('#<a [^>]*href="/obrok19/profil"#', $html);
        self::assertStringContainsString('class="tabbar', $html, 'the page wears the app shell');
        self::assertStringNotContainsString('Tohle tady není.', $html);
    }

    public function testACrossSiteLogoutExplainsItselfInCzech(): void
    {
        $response = $this->request($this->createApp(), 'POST', '/profil/tie-logout', [], ['Origin' => 'https://evil.example']);
        $html = (string) $response->getBody();

        self::assertSame(403, $response->getStatusCode());
        // the reader was logging out, so the page names that, not a login
        self::assertStringContainsString('Odhlášení se nepodařilo. Načti stránku a zkus to znovu.', $html);
        self::assertStringNotContainsString('Přihlášení se nepodařilo', $html);
        self::assertMatchesRegularExpression('#<a [^>]*href="/obrok19/profil"#', $html);
    }
}
