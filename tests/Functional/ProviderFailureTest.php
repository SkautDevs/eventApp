<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\UnknownParticipantException;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

final class ProviderFailureTest extends AppTestCase
{
    public function testProgramsPageDegradesGracefullyOnProviderOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            programsException: new ConnectException('down', new Request('GET', 'x')),
        );
        $app = $this->createApp(overrides: [ProgramProviderInterface::class => $provider]);

        $response = $this->request($app, 'GET', '/programy');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'Programy se nepodařilo načíst, zkuste to prosím později.',
            (string) $response->getBody(),
        );
    }

    public function testProgramsDegradeUnpersonalizedOnProviderOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            identityExceptionAfterFirstCall: new ConnectException('down', new Request('GET', 'x')),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
        ]);

        // the first call (TIE code login) goes through fine
        $login = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $login->getStatusCode());

        // on the second call (during GET /programy) kissj no longer answers
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        self::assertStringContainsString('Osobní program se nepodařilo načíst.', $html);
        // the user stays logged in, just without a highlighted program
        self::assertStringContainsString('TIE ABC123', $html);
    }

    public function testTieLoginDegradesGracefullyOnProviderOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            identityException: new ConnectException('down', new Request('GET', 'x')),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
        ]);

        $login = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $login->getStatusCode());

        // the TIE error belongs to the login screen, which now lives at /profil
        $html = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringContainsString('Přihlášení se teď nedaří', $html);
    }

    public function testAMalformedParticipantAnswerOnLoginDegradesLikeAnOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            identityException: new ProgramDataException('kissj sent no list of programmes for v3/programme/participant/tie/x'),
        );
        $app = $this->createApp(overrides: [ProgramProviderInterface::class => $provider]);

        $login = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);

        self::assertSame(302, $login->getStatusCode());
        $html = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringContainsString('Přihlášení se teď nedaří, zkuste to prosím později.', $html);
        self::assertStringNotContainsString('Odhlásit TIE', $html);
    }

    public function testProgramsLogOutOnUnknownParticipantAfterInitialLogin(): void
    {
        $provider = new ThrowingProgramProvider(
            identityExceptionAfterFirstCall: new UnknownParticipantException('Unknown TIE code: ABC123'),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
        ]);

        // the first call (TIE code login) goes through fine
        $login = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $login->getStatusCode());

        // the participant vanished in the meantime (kissj returns 404) → the user is logged out on the next load
        $response = $this->request($app, 'GET', '/programy');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Váš TIE kód už není platný, byli jste odhlášeni.', $html);
        self::assertStringNotContainsString('TIE ABC123', $html);
        self::assertStringContainsString('Přihlaste se', $html);
    }

    /**
     * The normal case when kissj is down is that BOTH calls fail in the same request.
     * The first one signs the participant out, and its explanation used to be
     * overwritten by the second one's notice — so the reader was logged out with no
     * reason given at all.
     */
    public function testTheLogoutExplanationSurvivesASecondFailureInTheSameRequest(): void
    {
        $provider = new ThrowingProgramProvider(
            programsException: new ConnectException('down', new Request('GET', 'x')),
            identityExceptionAfterFirstCall: new UnknownParticipantException('Unknown TIE code: ABC123'),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
        ]);

        self::assertSame(302, $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123'])->getStatusCode());

        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        self::assertStringNotContainsString('TIE ABC123', $html, 'the participant was not logged out');
        self::assertStringContainsString('Váš TIE kód už není platný, byli jste odhlášeni.', $html);
        self::assertStringContainsString('Programy se nepodařilo načíst, zkuste to prosím později.', $html);
    }

    /**
     * A 200 carrying something that is not programme data is the same failure to the
     * reader as a connection that never arrived: a notice, not a 500.
     */
    public function testAMalformedPayloadDegradesLikeAnOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            programsException: new ProgramDataException('kissj returned a non-array payload'),
        );
        $app = $this->createApp(overrides: [ProgramProviderInterface::class => $provider]);

        $response = $this->request($app, 'GET', '/programy');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'Programy se nepodařilo načíst, zkuste to prosím později.',
            (string) $response->getBody(),
        );
    }
}
